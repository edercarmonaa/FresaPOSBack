<?php

namespace Modules\Sales\Application\Services;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Cash\Contracts\ActiveCashSession;
use Modules\Cash\Contracts\CashMovements;
use Modules\Cash\Contracts\CashSessionUnavailable;
use Modules\Cash\Contracts\PaymentMethods;
use Modules\Core\Contracts\ApiCode;
use Modules\Core\Contracts\ApiExceptionMappings;
use Modules\Core\Contracts\DecimalAmount;
use Modules\Core\Contracts\IdempotentOperations;
use Modules\Core\Contracts\OperationIntent;
use Modules\Core\Contracts\OperationRejected;
use Modules\Core\Contracts\OperationResult;
use Modules\Core\Contracts\SaleCorrectionAudit;
use Modules\Core\Contracts\SecurityEvent;
use Modules\Core\Contracts\TransactionManager;
use Modules\Sales\Application\Ports\SalesStore;
use Modules\Sales\Application\SaleSnapshotCodec;
use Modules\Sales\Application\SalesRevision;
use Modules\Sales\Contracts\AddProduct;
use Modules\Sales\Contracts\DiscountApproval;
use Modules\Sales\Contracts\DiscountProposal;
use Modules\Sales\Contracts\Events\SaleChanged;
use Modules\Sales\Contracts\SaleCorrections;
use Modules\Sales\Contracts\SaleOperationReasons;
use Modules\Sales\Contracts\SaleSnapshot;
use Modules\Sales\Domain\PaymentAllocation;
use Modules\Sales\Domain\SalesConflict;
use Modules\Sales\Domain\SaleState;
use Modules\Sales\Domain\SalesText;
use Modules\Sales\Domain\SaleTotals;
use Modules\Sales\Http\Resources\SaleResource;
use Modules\Sales\Infrastructure\Persistence\CheckoutStore;
use Modules\Sales\Infrastructure\Persistence\CorrectionDraftStore;
use Modules\Sales\Infrastructure\Persistence\CorrectionStore;
use Modules\Sales\Policies\SalesPolicy;
use Modules\Users\Contracts\IdentityAccess;

final class CorrectPaidSale implements SaleCorrections
{
    public function __construct(private readonly IdempotentOperations $operations, private readonly TransactionManager $transactions,
        private readonly IdentityAccess $identities, private readonly SalesPolicy $policy, private readonly SalesStore $sales,
        private readonly ActiveCashSession $cash, private readonly CorrectionStore $store, private readonly PaymentMethods $methods,
        private readonly CheckoutStore $payments, private readonly CashMovements $movements, private readonly DiscountApproval $discounts,
        private readonly ApiExceptionMappings $exceptionMappings) {}

    private function authorize(string $actorId, bool $write = true, bool $confirm = false, bool $discount = false): void
    {
        $actor = $this->identities->inspect($actorId);
        foreach (['sales.view', ...($write ? ['sales.reopen', 'sales.special.authorize', 'sales.open.update', 'cash.view'] : []), ...($confirm ? ['sales.close'] : []), ...($discount ? ['sales.discounts.apply'] : [])] as $permission) {
            if ($actor === null || ! $this->policy->allows($actor, $permission)) {
                throw new AuthorizationException;
            }
        }
    }

    public function write(string $actorId, string $saleId, string $action, array $input, string $key, ?string $revision, ?Closure $access = null): array
    {
        $discount = $action === 'edit' && in_array($input['action'] ?? null, ['discount', 'discount-remove'], true);
        $this->authorize($actorId, confirm: $action === 'confirm', discount: $discount);
        $saleId = SalesText::id($saleId);
        try {
            $input = $this->normalizeInput($action, $input);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['correction' => $exception->getMessage()]);
        }
        $intent = new OperationIntent(OperationIntent::context($actorId), $key, 'sales.correction.'.$action, $action === 'edit' ? 'PATCH' : 'POST', $saleId,
            ['input' => $input, 'revision' => $revision], $actorId);
        $result = $this->operations->run($intent, function (bool $locking) use ($actorId, $action, $access, $discount): void {
            if ($locking) {
                $this->identities->lock();
                $access?->__invoke();
            }
            $this->authorize($actorId, confirm: $action === 'confirm', discount: $discount);
        }, function () use ($actorId, $saleId, $action, $input, $revision): OperationResult {
            try {
                $initial = $this->sales->findSale($saleId);
                $this->cash->requireActive($actorId, $initial->sale->cashSessionId);
                $sale = $this->sales->findSale($saleId, lock: true);
                $this->consistent($sale);
                if ($action === 'reopen') {
                    SalesRevision::requireCurrent($sale, $revision);
                    if ($this->store->exists($saleId)) {
                        throw new SalesConflict('Ya existe una corrección activa.');
                    }
                    $data = SaleSnapshotCodec::encode($sale);
                    $row = $this->store->insert(['id' => strtolower((string) Str::ulid()), 'sale_id' => $saleId, 'cash_session_id' => $sale->sale->cashSessionId,
                        'reopened_by' => $actorId, 'reopened_at' => now()->utc(), 'observation' => $input['observation'], 'status' => 'active',
                        'original_snapshot' => $data, 'draft_snapshot' => $data, 'version' => 1, 'additional_paid' => '0.0000']);
                } else {
                    if (! $this->store->exists($saleId)) {
                        throw new SalesConflict('La venta ya no tiene una corrección activa.');
                    }
                    $row = $this->store->active($saleId, lock: true);
                    if ($revision === null || ! hash_equals('"'.self::revision($row).'"', $revision)) {
                        throw new SalesConflict('Actualiza la corrección antes de continuar.');
                    }
                    if (SalesRevision::of($sale) !== SalesRevision::of(SaleSnapshotCodec::decode($row['original_snapshot']))) {
                        throw new SalesConflict('La historia confirmada cambió; no se puede aplicar el borrador.');
                    }
                    $row = match ($action) {
                        'edit' => $this->edit($actorId, $row, $input), 'confirm' => $this->confirm($actorId, $row, $input['payments']),
                        'discard' => $this->store->update($row['id'], ['status' => 'discarded', 'completed_at' => now()->utc(), 'confirmed_by' => $actorId,
                            'result_snapshot' => $row['original_snapshot'], 'version' => $row['version'] + 1]),
                        default => throw new \InvalidArgumentException('Operación de corrección no permitida.'),
                    };
                }

                return new OperationResult(200, $this->present($row), $saleId);
            } catch (ModelNotFoundException) {
                throw new OperationRejected(ApiCode::NOT_FOUND);
            } catch (SalesConflict|CashSessionUnavailable $exception) {
                throw new OperationRejected(ApiCode::CONFLICT, ['correction' => [$exception->getMessage()]]);
            } catch (ValidationException $exception) {
                throw new OperationRejected(ApiCode::VALIDATION_ERROR, $exception->errors());
            } catch (\InvalidArgumentException $exception) {
                throw new OperationRejected(ApiCode::VALIDATION_ERROR, ['correction' => [$exception->getMessage()]]);
            } catch (\Throwable $exception) {
                // Clasificación pública de Core; sin importar excepciones privadas de Catalog.
                if ($this->exceptionMappings->codeFor($exception) === ApiCode::CONFLICT) {
                    throw new OperationRejected(ApiCode::CONFLICT, ['correction' => ['Un recurso necesario para la corrección ya no está disponible. Actualiza el borrador.']]);
                }
                throw $exception;
            }
        });

        return $result->data;
    }

    public function normalizeInput(string $action, array $input): array
    {
        $validator = validator($input, match ($action) {
            'reopen' => ['observation' => 'sometimes|nullable|string|max:150'],
            'confirm' => ['payments' => 'present|array|list|max:20'], 'discard' => [],
            'edit' => $this->editRules($input['action'] ?? ''), default => throw new \InvalidArgumentException('Invalid correction operation.'),
        });
        if (array_diff(array_keys($input), array_keys($validator->getRules())) !== []) {
            throw ValidationException::withMessages(['data' => 'Campos no permitidos.']);
        }
        $data = $validator->validate();
        if ($action === 'reopen') {
            $data['observation'] = SalesText::normalize($data['observation'] ?? null, 150);
        }
        if ($action === 'edit') {
            if (in_array($data['action'], ['add', 'quantity'], true)) {
                $data['quantity'] = SalesText::quantity($data['quantity'], (int) config('sales.max_quantity'));
            }
            if (array_key_exists('note', $data)) {
                $data['note'] = SalesText::normalize($data['note'], $data['action'] === 'note' ? 150 : 100);
            }
            foreach (['product_id', 'item_id'] as $field) {
                if (isset($data[$field])) {
                    $data[$field] = SalesText::id($data[$field]);
                }
            }
            if ($data['action'] === 'discount') {
                $data['value'] = (new DiscountProposal($data['type'], $data['value']))->value;
            }
        }
        if ($action === 'confirm') {
            foreach ($data['payments'] as &$payment) {
                if (! is_array($payment) || array_diff(array_keys($payment), ['payment_method_id', 'applied_amount', 'received_cash', 'reference']) !== []) {
                    throw ValidationException::withMessages(['payments' => 'Campos de pago no permitidos.']);
                }
                $payment['payment_method_id'] = SalesText::id($payment['payment_method_id'] ?? '');
                $payment['applied_amount'] = DecimalAmount::parse($payment['applied_amount'] ?? null)->value;
                $payment['received_cash'] = isset($payment['received_cash']) ? DecimalAmount::parse($payment['received_cash'])->value : null;
                $payment['reference'] ??= null;
            } unset($payment);
        }

        return $data;
    }

    public function editRules(mixed $action): array
    {
        return ['action' => 'required|string|in:add,quantity,item-note,note,remove,discount,discount-remove'] + match ($action) {
            'add' => ['product_id' => 'required|ulid', 'quantity' => 'required', 'note' => 'sometimes|nullable|string|max:100'],
            'quantity' => ['item_id' => 'required|ulid', 'quantity' => 'required'],
            'item-note' => ['item_id' => 'required|ulid', 'note' => 'present|nullable|string|max:100'],
            'note' => ['note' => 'present|nullable|string|max:150'],
            'remove' => ['item_id' => 'required|ulid', 'reason_code' => 'required|string|max:64'],
            'discount' => ['type' => 'required|string|in:percent,fixed', 'value' => 'required'], default => [],
        };
    }

    private function edit(string $actorId, array $row, array $input): array
    {
        $draft = new CorrectionDraftStore(SaleSnapshotCodec::decode($row['draft_snapshot']), app(SaleOperationReasons::class));
        $sales = app()->makeWith(ManageSales::class, ['store' => $draft, 'removals' => $draft]);
        $id = $row['sale_id'];
        match ($input['action']) {
            'add' => $sales->addProduct($actorId, $id, new AddProduct($input['product_id'], $input['quantity'], $input['note'] ?? null)),
            'quantity' => $sales->changeQuantity($actorId, $id, $input['item_id'], $input['quantity']),
            'item-note' => $sales->editItemNote($actorId, $id, $input['item_id'], $input['note']),
            'note' => $sales->editNote($actorId, $id, $input['note']),
            'remove' => $sales->removeItem($actorId, $id, $input['item_id'], $input['reason_code']),
            'discount' => $sales->setDiscount($actorId, $id, new DiscountProposal($input['type'], $input['value'])),
            'discount-remove' => $sales->setDiscount($actorId, $id, null),
            default => throw new \InvalidArgumentException('Cambio de corrección no permitido.'),
        };

        return $this->store->update($row['id'], ['draft_snapshot' => SaleSnapshotCodec::encode($draft->snapshot()), 'version' => $row['version'] + 1]);
    }

    private function consistent(SaleSnapshot $sale): void
    {
        $totals = SaleTotals::calculate($sale);
        $paid = DecimalAmount::from('0.0000');
        foreach ($sale->payments as $payment) {
            if ($payment['cash_session_id'] !== $sale->sale->cashSessionId) {
                throw new SalesConflict('Pagos fuera de la sesión original.');
            }
            $paid = $paid->add(DecimalAmount::from($payment['applied_amount']));
        }
        if ($sale->sale->state !== SaleState::Closed || $sale->sale->folio === null || $sale->sale->closedAt === null || $sale->sale->closedBy === null
            || $paid->value !== $sale->sale->total || $totals !== ['subtotal' => $sale->sale->subtotal, 'discount_amount' => $sale->sale->discount, 'total' => $sale->sale->total]
            || $totals['subtotal'] === '0.0000') {
            throw new SalesConflict('La venta no tiene un cierre financiero reconciliable.');
        }
    }

    private function confirm(string $actorId, array $row, array $payments): array
    {
        $original = SaleSnapshotCodec::decode($row['original_snapshot']);
        $draft = SaleSnapshotCodec::decode($row['draft_snapshot']);
        $totals = SaleTotals::calculate($draft);
        if ($totals['subtotal'] === '0.0000' || $totals['total'] !== $draft->sale->total) {
            throw new SalesConflict('Revisa las líneas del borrador.');
        }
        try {
            $difference = DecimalAmount::from($draft->sale->total)->subtract(DecimalAmount::from($original->sale->total));
        } catch (\InvalidArgumentException) {
            throw new SalesConflict('Esta corrección requiere devolución de dinero y no puede confirmarse. Descarta el borrador o corrígelo.');
        }
        $actor = $this->identities->inspect($actorId);
        $plan = PaymentAllocation::calculate($difference->value, $this->methods->available($actorId), $payments, in_array('cash.administrative.execute', $actor->permissions, true));
        $changes = self::changes($original, $draft);
        if ($changes !== []) {
            $this->store->apply($row, $draft);
            if ($draft->sale->discountAuthorization !== null) {
                $this->discounts->requireApproved($actorId, $row['sale_id'], $draft->sale->subtotal, $draft->sale->discount);
            }
            $this->payments->persist($actorId, $row['sale_id'], $row['cash_session_id'], $plan, now()->toImmutable()->utc());
            $result = $this->sales->findSale($row['sale_id']);
            $this->consistent($result);
            $this->movements->balance($actorId, $row['cash_session_id']);
            event(new SecurityEvent('sales.correction.confirmed', actorId: $actorId, recordId: $row['sale_id'],
                correction: new SaleCorrectionAudit($row['id'], $row['cash_session_id'], $original->sale->folio, $changes, $original->sale->total, $draft->sale->total, $difference->value)));
            $this->transactions->afterCommit(function () use ($actorId, $row): void {
                try {
                    event(new SaleChanged($row['sale_id'], $actorId, 'corrected'));
                } catch (\Throwable $e) {
                    try {
                        Log::error('Falló un consumidor secundario de corrección.', ['sale_id' => $row['sale_id'], 'exception_type' => $e::class]);
                    } catch (\Throwable) {
                    }
                }
            });
        } else {
            $result = $original;
        }

        return $this->store->update($row['id'], ['status' => $changes === [] ? 'unchanged' : 'confirmed', 'completed_at' => now()->utc(),
            'confirmed_by' => $actorId, 'additional_paid' => $difference->value, 'result_snapshot' => SaleSnapshotCodec::encode($result),
            'applying_transaction_id' => null, 'version' => $row['version'] + 1]);
    }

    public static function changes(SaleSnapshot $before, SaleSnapshot $after): array
    {
        $changes = [];
        if (array_map(get_object_vars(...), $before->items) !== array_map(get_object_vars(...), $after->items)) {
            $changes[] = 'items';
        }
        if ($before->sale->note !== $after->sale->note) {
            $changes[] = 'note';
        }
        $descriptor = fn ($sale) => $sale->sale->discountAuthorization === null ? null : array_intersect_key($sale->sale->discountAuthorization, array_flip(['type', 'value']));
        if ($descriptor($before) !== $descriptor($after)) {
            $changes[] = 'discount';
        }

        return $changes;
    }

    public static function revision(array $row): string
    {
        return hash_hmac('sha256', $row['id'].':'.$row['version'].':'.$row['status'], (string) config('app.key'));
    }

    private function present(array $row): array
    {
        $original = SaleSnapshotCodec::decode($row['original_snapshot']);
        $draft = SaleSnapshotCodec::decode($row['draft_snapshot']);
        $refund = false;
        try {
            $difference = DecimalAmount::from($draft->sale->total)->subtract(DecimalAmount::from($original->sale->total))->value;
        } catch (\InvalidArgumentException) {
            $refund = true;
            $difference = '-'.DecimalAmount::from($original->sale->total)->subtract(DecimalAmount::from($draft->sale->total))->value;
        }
        $resource = fn ($snapshot) => (new SaleResource($snapshot))->resolve(request());

        return ['id' => $row['id'], 'sale_id' => $row['sale_id'], 'cash_session_id' => $row['cash_session_id'], 'folio' => $original->sale->folio,
            'status' => $row['status'], 'reopened_by' => $row['reopened_by'], 'reopened_at' => CarbonImmutable::parse($row['reopened_at'])->utc()->toISOString(),
            'observation' => $row['observation'], 'confirmed_by' => $row['confirmed_by'] ?? null,
            'confirmed_at' => isset($row['completed_at']) ? CarbonImmutable::parse($row['completed_at'])->utc()->toISOString() : null,
            'revision' => self::revision($row), 'original' => $resource($original), 'proposed' => $resource($draft),
            'sale' => $resource(SaleSnapshotCodec::decode($row['result_snapshot'] ?? $row['original_snapshot'])), 'changes' => self::changes($original, $draft),
            'financial' => ['original_total' => $original->sale->total, 'proposed_total' => $draft->sale->total, 'difference' => $difference,
                'additional_paid' => DecimalAmount::from(str_starts_with($row['additional_paid'], '.') ? '0'.$row['additional_paid'] : $row['additional_paid'])->value,
                'requires_refund' => $refund, 'payment_reclassification_supported' => false]];
    }

    public function current(string $actorId, string $saleId): array
    {
        $this->authorize($actorId, write: false);
        $this->sales->findSale(SalesText::id($saleId));

        return $this->present($this->store->active($saleId));
    }

    public function history(string $actorId, string $saleId, int $page = 1): array
    {
        $this->authorize($actorId, write: false);
        $this->sales->findSale(SalesText::id($saleId));
        if ($page < 1 || $page > 1000000) {
            throw ValidationException::withMessages(['page' => 'Página inválida.']);
        }
        $history = $this->store->history($saleId, $page);
        $history['items'] = array_map($this->present(...), $history['items']);

        return $history;
    }
}
