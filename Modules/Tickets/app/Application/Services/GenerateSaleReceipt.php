<?php

namespace Modules\Tickets\Application\Services;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Modules\Core\Contracts\ApiCode;
use Modules\Core\Contracts\IdempotentOperations;
use Modules\Core\Contracts\OperationIntent;
use Modules\Core\Contracts\OperationRejected;
use Modules\Core\Contracts\OperationResult;
use Modules\Core\Contracts\ReceiptAudit;
use Modules\Core\Contracts\SecurityEvent;
use Modules\Core\Contracts\TransactionManager;
use Modules\Sales\Contracts\ConfirmedSalesDocuments;
use Modules\Tickets\Contracts\ReceiptData;
use Modules\Tickets\Contracts\SaleReceipts;
use Modules\Users\Contracts\IdentityAccess;

final class GenerateSaleReceipt implements SaleReceipts
{
    public function __construct(private readonly ConfirmedSalesDocuments $documents, private readonly IdentityAccess $identities,
        private readonly TransactionManager $transactions, private readonly IdempotentOperations $operations) {}

    private function authorize(string $actorId, bool $print = false): void
    {
        $actor = $this->identities->inspect($actorId);
        if (! $actor || ! $actor->isActive || $actor->mustChangePassword
            || array_diff(['sales.view', 'tickets.view', ...($print ? ['tickets.print'] : [])], $actor->permissions) !== []) {
            throw new AuthorizationException;
        }
    }

    public function view(string $actorId, ?string $saleId = null, ?string $folio = null, ?Closure $access = null): ReceiptData
    {
        $this->authorize($actorId);

        return $this->transactions->run(function () use ($actorId, $saleId, $folio, $access): ReceiptData {
            $this->identities->lock();
            $access?->__invoke();
            $this->authorize($actorId);

            return $this->build($actorId, $saleId, $folio);
        });
    }

    public function reprint(string $actorId, string $saleId, string $revision, string $key, ?Closure $access = null): ReceiptData
    {
        $this->authorize($actorId, true);
        $saleId = strtolower($saleId);
        $intent = new OperationIntent(OperationIntent::context($actorId), $key, 'tickets.reprint', 'POST', $saleId,
            ['revision' => $revision], $actorId);
        $preparation = new class
        {
            public ?ReceiptData $receipt = null;
        };
        $result = $this->operations->run($intent, function (bool $locking) use ($actorId, $saleId, $revision, $access, $preparation): void {
            if ($locking) {
                $this->identities->lock();
                $access?->__invoke();
            }
            $this->authorize($actorId, true);
            if ($locking) {
                $preparation->receipt = $this->build($actorId, $saleId);
                if (! hash_equals($preparation->receipt->revision, $revision)) {
                    throw new OperationRejected(ApiCode::CONFLICT, ['receipt' => ['La versión cambió. Abre una vista previa actualizada.']]);
                }
            }
        }, function () use ($preparation, $actorId, $saleId): OperationResult {
            $receipt = $preparation->receipt;
            if (! $receipt instanceof ReceiptData) {
                throw new \LogicException('El comprobante requiere una lectura autorizada y coherente.');
            }
            event(new SecurityEvent('tickets.reprint.requested', actorId: $actorId, recordId: $saleId,
                receipt: new ReceiptAudit($receipt->folio, $receipt->revision)));

            return new OperationResult(200, $receipt->jsonSerialize(), $saleId);
        });

        return ReceiptData::fromArray($result->data);
    }

    private function build(string $actorId, ?string $saleId, ?string $folio = null): ReceiptData
    {
        $document = $this->documents->find($actorId, $saleId, $folio);
        $sale = $document->snapshot->sale;
        $timezone = (string) config('pos.business_timezone', 'America/Mexico_City');
        $items = [];
        foreach ($document->snapshot->items as $item) {
            if ($item->removedAt === null) {
                $items[] = ['name' => $item->name, 'quantity' => $item->quantity, 'unit_price' => $item->unitPrice,
                    'line_total' => $item->lineTotal, 'note' => $item->note];
            }
        }
        $payments = [];
        $names = ['cash' => 'Efectivo', 'card' => 'Tarjeta', 'transfer' => 'Transferencia', 'other' => 'Otro autorizado'];
        foreach ($document->snapshot->payments as $payment) {
            $payments[] = ['method_code' => $payment['method_code'], 'method_name' => $names[$payment['method_code']],
                'applied_amount' => $payment['applied_amount'], 'received_cash' => $payment['received_cash'], 'change_cash' => $payment['change_cash']];
        }
        $establishment = [];
        foreach (['name', 'branch', 'address', 'phone', 'fiscal_id', 'footer'] as $field) {
            $value = config('tickets.establishment.'.$field);
            if ($field === 'fiscal_id' && config('tickets.print_fiscal_id') !== true) {
                continue;
            }
            if (is_string($value) && trim($value) !== '') {
                $establishment[$field] = trim($value);
            }
        }
        $data = ['sale_id' => $sale->id, 'folio' => $sale->folio, 'closed_at' => $sale->closedAt,
            'local_closed_at' => CarbonImmutable::parse($sale->closedAt)->setTimezone($timezone)->format('Y-m-d H:i:s'),
            'timezone' => $timezone, 'sale_type' => $sale->type->value, 'items' => $items, 'subtotal' => $sale->subtotal,
            'discount_amount' => $sale->discount, 'discount' => $sale->discountAuthorization === null ? null : [
                'type' => $sale->discountAuthorization['type'], 'value' => $sale->discountAuthorization['value']],
            'total' => $sale->total, 'payments' => $payments, 'note' => $sale->note, 'establishment' => $establishment,
            'employee' => $sale->closedBy === null ? null : $this->identities->inspect($sale->closedBy)?->name,
            'correction' => $document->correction];
        $data['revision'] = hash_hmac('sha256', json_encode([$document->revision, $data], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), (string) config('app.key'));

        return ReceiptData::fromArray($data);
    }
}
