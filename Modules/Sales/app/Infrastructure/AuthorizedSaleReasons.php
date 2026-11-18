<?php

namespace Modules\Sales\Infrastructure;

use Illuminate\Support\Facades\DB;
use Modules\Sales\Contracts\CancellationApproval;
use Modules\Sales\Contracts\LineRemovalApproval;
use Modules\Sales\Contracts\SaleOperationReasons;
use Modules\Sales\Domain\SalesConflict;
use Modules\Sales\Infrastructure\Persistence\ReasonStore;

final class AuthorizedSaleReasons implements CancellationApproval, LineRemovalApproval
{
    public function __construct(private readonly SaleOperationReasons $reasons, private readonly ReasonStore $store) {}

    public function requireApproved(string $actorId, string $saleId, string $itemId, string $reasonCode): void
    {
        $this->store->transaction();
        $reason = $this->reasons->approved($actorId, 'sale_item_removal', $reasonCode);
        unset($reason['revision']);
        $changed = DB::table('sales_items')->where('id', $itemId)->where('sale_id', $saleId)->whereNull('removed_at')
            ->update(['removal_reason_id' => $reason['id'], 'removal_reason_snapshot' => json_encode($reason, JSON_THROW_ON_ERROR)]);
        if ($changed !== 1) {
            throw new SalesConflict('La línea ya no está disponible.');
        }
    }

    public function reason(string $actorId, string $saleId, string $code): string
    {
        $this->store->transaction();
        $reason = $this->reasons->approved($actorId, 'sale_cancellation', $code);
        if (DB::table('sales_payments')->where('sale_id', $saleId)->where('status', 'confirmed')->exists()) {
            throw new SalesConflict('Una venta con pagos confirmados no puede cancelarse.');
        }
        unset($reason['revision']);
        $changed = DB::table('sales_sales')->where('id', $saleId)->whereIn('status', ['open', 'paused'])
            ->update(['cancellation_reason_id' => $reason['id'], 'cancellation_reason_snapshot' => json_encode($reason, JSON_THROW_ON_ERROR)]);
        if ($changed !== 1) {
            throw new SalesConflict('La venta no admite cancelación.');
        }

        return $reason['name'];
    }
}
