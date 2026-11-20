<?php

namespace Modules\Sales\Http\Livewire;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Auth\Contracts\AuthenticatedRequestAccess;
use Modules\Cash\Contracts\PaymentMethods;
use Modules\Catalog\Contracts\CatalogAdministration;
use Modules\Catalog\Contracts\ProductQuery;
use Modules\Core\Contracts\DecimalAmount;
use Modules\Core\Contracts\IdempotentOperations;
use Modules\Core\Contracts\OperationIntent;
use Modules\Core\Contracts\OperationRejected;
use Modules\Sales\Application\SalesRevision;
use Modules\Sales\Application\Services\CorrectPaidSale;
use Modules\Sales\Contracts\SaleCorrections;
use Modules\Sales\Contracts\SaleOperationReasons;
use Modules\Sales\Contracts\SalesOperations;
use Modules\Sales\Domain\PaymentAllocation;
use Modules\Sales\Http\PosAccess;
use Modules\Sales\Http\PosIntentRegistry;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class CorrectionPanel extends Component
{
    #[Locked]
    public string $saleId;

    #[Locked]
    public string $actorContext = '';

    #[Locked]
    public string $key = '';

    #[Locked]
    public string $revision = '';

    #[Locked]
    public string $action = '';

    #[Locked]
    public string $itemId = '';

    #[Locked]
    public bool $opened = false;

    #[Locked]
    public bool $submitted = false;

    #[Locked]
    public array $frozen = [];

    #[Locked]
    public array $result = [];

    #[Locked]
    public array $preview = [];

    #[Locked]
    public int $historyPage = 1;

    #[Locked]
    public int $productPage = 1;

    #[Locked]
    public int $reasonPage = 1;

    public string $observation = '';

    public string $note = '';

    public string $quantity = '1';

    public string $productId = '';

    public string $search = '';

    public string $reasonCode = '';

    public string $discountType = 'percent';

    public string $discountValue = '';

    public array $payments = [];

    public string $status = '';

    private bool $written = false;

    private function actor(bool $write = false): string
    {
        $actor = app(PosAccess::class)->require();
        abort_if($this->actorContext !== '' && $this->actorContext !== $actor->id, 403);
        if ($write) {
            abort_unless(array_diff(['sales.reopen', 'sales.special.authorize', 'sales.open.update', 'cash.view'], $actor->permissions) === [], 403);
        }

        return $actor->id;
    }

    private function path(): string
    {
        return 'sales_correction_intents.'.OperationIntent::context($this->actor()).'.'.$this->saleId;
    }

    public function mount(string $saleId): void
    {
        $this->saleId = $saleId;
        $this->actorContext = $this->actor();
        $intent = session()->get($this->path());
        if (is_array($intent)) {
            [$this->key, $this->revision, $this->action, $this->frozen] = $intent;
            $this->opened = $this->submitted = true;
            $this->queryOperation();
        }
    }

    private function current(): ?array
    {
        try {
            return app(SaleCorrections::class)->current($this->actor(), $this->saleId);
        } catch (ModelNotFoundException) {
            return null;
        }
    }

    public function prepare(string $action, string $itemId = ''): void
    {
        $actor = $this->actor(true);
        abort_unless(in_array($action, ['reopen', 'add', 'quantity', 'item-note', 'note', 'remove', 'discount', 'discount-remove', 'confirm', 'discard'], true), 422);
        if ($this->submitted && in_array(app(IdempotentOperations::class)->status(OperationIntent::context($actor), $this->key)->state, ['pending', 'not_found'], true)) {
            $this->addError('correction', 'Consulta y resuelve la intención anterior; conserva su clave.');

            return;
        }
        $current = $this->current();
        $sale = app(SalesOperations::class)->sale($actor, $this->saleId);
        abort_unless($sale->sale->folio !== null && (($action === 'reopen') === ($current === null)), 409);
        $this->resetErrorBag();
        $this->action = $action;
        $this->itemId = $itemId;
        $this->key = (string) Str::uuid();
        $this->revision = '"'.($action === 'reopen' ? SalesRevision::of($sale) : $current['revision']).'"';
        $this->opened = true;
        $this->submitted = false;
        $this->frozen = $this->result = $this->payments = $this->preview = [];
        $this->observation = $this->reasonCode = $this->productId = $this->discountValue = '';
        $this->discountType = 'percent';
        $this->quantity = '1';
        $this->note = $current['proposed']['note'] ?? '';
        foreach ($current['proposed']['items'] ?? [] as $item) {
            if ($item['id'] === $itemId) {
                $this->quantity = (string) $item['quantity'];
                $this->note = $item['note'] ?? '';
            }
        }
        if ($action === 'confirm' && ! $current['financial']['requires_refund'] && $current['financial']['difference'] !== '0.0000') {
            $this->addPayment();
        }
    }

    public function addPayment(): void
    {
        $actor = $this->actor(true);
        abort_if($this->submitted || count($this->payments) >= 20, 422);
        $methods = app(PaymentMethods::class)->available($actor);
        $cash = collect($methods)->firstWhere('code', 'cash');
        $this->payments[] = ['payment_method_id' => $cash->id ?? '', 'applied_amount' => '', 'received_cash' => null, 'reference' => null];
    }

    public function removePayment(int $index): void
    {
        $this->actor(true);
        abort_if($this->submitted, 422);
        unset($this->payments[$index]);
        $this->payments = array_values($this->payments);
    }

    private function input(): array
    {
        if (in_array($this->action, ['add', 'quantity'], true) && preg_match('/\A(?:[1-9][0-9]?|100)\z/', $this->quantity) !== 1) {
            throw ValidationException::withMessages(['quantity' => 'Usa una cantidad entera de 1 a 100.']);
        }
        if ($this->action === 'discount' && $this->discountType === 'percent' && preg_match('/\A(?:[1-9][0-9]?|100)\z/', $this->discountValue) !== 1) {
            throw ValidationException::withMessages(['discount' => 'Usa un porcentaje entero de 1 a 100.']);
        }

        return match ($this->action) {
            'reopen' => ['observation' => $this->observation],
            'add' => ['action' => 'add', 'product_id' => $this->productId, 'quantity' => (int) $this->quantity, 'note' => $this->note],
            'quantity' => ['action' => 'quantity', 'item_id' => $this->itemId, 'quantity' => (int) $this->quantity],
            'item-note' => ['action' => 'item-note', 'item_id' => $this->itemId, 'note' => $this->note],
            'note' => ['action' => 'note', 'note' => $this->note],
            'remove' => ['action' => 'remove', 'item_id' => $this->itemId, 'reason_code' => $this->reasonCode],
            'discount' => ['action' => 'discount', 'type' => $this->discountType, 'value' => $this->discountType === 'percent' ? (int) $this->discountValue : $this->discountValue],
            'discount-remove', 'discard' => $this->action === 'discard' ? [] : ['action' => 'discount-remove'],
            'confirm' => ['payments' => array_map(function ($payment) {
                $payment['received_cash'] = ($payment['received_cash'] ?? '') === '' ? null : $payment['received_cash'];
                $payment['reference'] = ($payment['reference'] ?? '') === '' ? null : $payment['reference'];

                return $payment;
            }, $this->payments)],
            default => throw new \InvalidArgumentException('Operación inválida.'),
        };
    }

    public function calculate(): void
    {
        $actor = $this->actor(true);
        abort_unless($this->opened && $this->action === 'confirm' && ! $this->submitted, 422);
        $this->resetErrorBag();
        $this->preview = [];
        try {
            $current = $this->current();
            abort_unless($current && ! $current['financial']['requires_refund'], 409);
            $plan = PaymentAllocation::calculate($current['financial']['difference'], app(PaymentMethods::class)->available($actor), $this->input()['payments'],
                in_array('cash.administrative.execute', app(PosAccess::class)->require()->permissions, true));
            $change = DecimalAmount::from('0.0000');
            foreach ($plan->payments as $payment) {
                if ($payment->changeCash !== null) {
                    $change = $change->add(DecimalAmount::from($payment->changeCash));
                }
            }
            $this->preview = ['total' => $plan->total, 'remaining' => $plan->remaining, 'cash_applied' => $plan->cashApplied, 'change' => $change->value];
        } catch (\InvalidArgumentException $e) {
            $this->addError('payments', $e->getMessage());
        }
    }

    public function updated(string $property): void
    {
        if (str_starts_with($property, 'payments')) {
            $this->preview = [];
        }
        if (preg_match('/\Apayments\.([0-9]+)\.payment_method_id\z/', $property, $match) === 1) {
            $this->payments[(int) $match[1]]['received_cash'] = null;
        }
    }

    public function submit(): void
    {
        $actor = $this->actor(true);
        if ($this->written) {
            return;
        }
        $this->resetErrorBag();
        try {
            abort_unless($this->opened || $this->submitted, 422);
            $operation = in_array($this->action, ['reopen', 'confirm', 'discard'], true) ? $this->action : 'edit';
            if (! $this->submitted) {
                if ($this->action === 'confirm' && ! ($this->current()['financial']['requires_refund'] ?? false) && ($this->current()['financial']['difference'] ?? '0.0000') !== '0.0000' && $this->preview === []) {
                    throw ValidationException::withMessages(['payments' => 'Calcula y revisa el cobro adicional antes de confirmar.']);
                }
                $this->frozen = app(CorrectPaidSale::class)->normalizeInput($operation, $this->input());
                $this->submitted = true;
            }
            session()->put($this->path(), [$this->key, $this->revision, $this->action, $this->frozen]);
            session()->save();
            app(PosIntentRegistry::class)->begin($actor, $this->key, 'sales.correction.'.$operation, $this->saleId);
            $this->written = true;
            $this->result = app(SaleCorrections::class)->write($actor, $this->saleId, $operation, $this->frozen, $this->key, $this->revision,
                fn () => app(AuthenticatedRequestAccess::class)->requireWeb(request(), $actor));
            $this->opened = false;
            $this->status = match ($this->result['status']) {
                'confirmed' => 'Venta corregida. Folio y primer cierre conservados.', 'unchanged' => 'Corrección finalizada sin cambios efectivos ni evento funcional.',
                'discarded' => 'Borrador descartado. La venta y sus pagos permanecen vigentes.', default => 'Borrador guardado. Los pagos y el saldo confirmado todavía no cambian.',
            };
            $this->dispatch('sale-operation-completed', saleId: $this->saleId);
        } catch (\InvalidArgumentException $e) {
            $this->addError('correction', $e->getMessage());
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }
        } catch (OperationRejected $e) {
            $this->addError('correction', $e->errors['correction'][0] ?? $e->getMessage());
        } catch (\Throwable $e) {
            if ($e instanceof AuthorizationException || $e instanceof HttpExceptionInterface) {
                throw $e;
            }
            report($e);
            $this->addError('correction', 'Resultado no confirmado. Conserva la intención y consulta su estado antes de reintentar.');
        }
    }

    public function queryOperation(): void
    {
        $actor = $this->actor();
        if ($this->key === '') {
            return;
        }
        $state = app(IdempotentOperations::class)->status(OperationIntent::context($actor), $this->key);
        $this->status = 'Estado de la intención: '.($state->expired ? 'expired' : $state->state).'. Conservamos su clave.';
        if ($state->state === 'completed' && $state->resourceId === $this->saleId && str_starts_with($state->operation ?? '', 'sales.correction.')) {
            $this->opened = false;
            $this->status = 'Resultado consultado sin repetir la operación. Revisa la corrección y su historial.';
            $this->dispatch('sale-operation-completed', saleId: $this->saleId);
        }
    }

    public function goPage(string $list, int $page): void
    {
        $this->actor();
        abort_unless(in_array($list, ['historyPage', 'productPage', 'reasonPage'], true) && $page > 0 && $page <= 1000000, 422);
        $this->{$list} = $page;
    }

    public function updatedSearch(): void
    {
        $this->productPage = 1;
    }

    public function render(): View
    {
        $actor = $this->actor();
        $identity = app(PosAccess::class)->require();
        $canWrite = array_diff(['sales.reopen', 'sales.special.authorize', 'sales.open.update', 'cash.view'], $identity->permissions) === [];
        $canDiscount = in_array('sales.discounts.apply', $identity->permissions, true);
        $canConfirm = in_array('sales.close', $identity->permissions, true);
        $current = $this->current();
        $history = app(SaleCorrections::class)->history($actor, $this->saleId, $this->historyPage);
        $products = $reasons = null;
        if ($this->opened && $this->action === 'add') {
            $products = app(CatalogAdministration::class)->products($actor, new ProductQuery(name: $this->search, page: $this->productPage, perPage: 10, availableForSale: true));
        }
        if ($this->opened && $this->action === 'remove') {
            $reasons = app(SaleOperationReasons::class)->search($actor, ['operation' => 'sale_item_removal', 'page' => $this->reasonPage]);
        }
        $methods = $canWrite && $this->opened && $this->action === 'confirm' ? app(PaymentMethods::class)->available($actor) : [];

        return view('sales::livewire.correction-panel', compact('current', 'history', 'canWrite', 'canDiscount', 'canConfirm', 'products', 'reasons', 'methods'));
    }
}
