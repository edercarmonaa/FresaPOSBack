<?php

namespace Modules\Core\Contracts;

use Illuminate\Support\Str;

final readonly class SaleOperationAudit
{
    public array $reason;

    public function __construct(public string $saleId, public string $stateBefore, public string $stateAfter, array $reason, public ?array $item = null)
    {
        $this->reason = ReasonChangeAudit::snapshot($reason);
        if (! Str::isUlid($saleId) || ! in_array($stateBefore, ['open', 'paused'], true)
            || ($item === null ? $stateAfter !== 'cancelled' || $this->reason['operation'] !== 'sale_cancellation'
                : $stateBefore !== 'open' || $stateAfter !== 'open' || $this->reason['operation'] !== 'sale_item_removal')) {
            throw new \InvalidArgumentException('Operación de venta auditada inválida.');
        }
        if ($item !== null) {
            $fields = ['item_id', 'product_id', 'name', 'quantity', 'unit_price', 'line_total'];
            if (count($item) !== count($fields) || array_diff($fields, array_keys($item)) !== []
                || ! Str::isUlid($item['item_id']) || ! Str::isUlid($item['product_id'])
                || ! is_string($item['name']) || $item['name'] === '' || mb_strlen($item['name']) > 150
                || ! is_int($item['quantity']) || $item['quantity'] < 1
                || DecimalAmount::from($item['unit_price'])->multiply($item['quantity'])->value !== $item['line_total']) {
                throw new \InvalidArgumentException('Línea retirada auditada inválida.');
            }
        }
    }
}
