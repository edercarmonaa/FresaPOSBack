<?php

namespace Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Sales\Application\SalesRevision;
use Modules\Sales\Contracts\SaleSnapshot;
use Modules\Sales\Contracts\SaleSummary;

class SaleResource extends JsonResource
{
    public function __construct(private readonly SaleSummary|SaleSnapshot $sale)
    {
        parent::__construct($sale);
    }

    public function toArray(Request $request): array
    {
        $sale = $this->sale instanceof SaleSnapshot ? $this->sale->sale : $this->sale;
        $result = ['id' => $sale->id, 'type' => $sale->type->value, 'state' => $sale->state->value,
            'table_id' => $sale->tableId, 'created_by' => $sale->createdBy, 'note' => $sale->note,
            'subtotal' => $sale->subtotal, 'discount' => $sale->discount, 'total' => $sale->total,
            'created_at' => $sale->createdAt, 'updated_at' => $sale->updatedAt, 'closed_at' => $sale->closedAt, 'cancelled_at' => $sale->cancelledAt];
        if ($this->sale instanceof SaleSnapshot) {
            $result['revision'] = SalesRevision::of($this->sale);
            $result['items'] = array_map(fn ($item) => (new SaleItemResource($item))->resolve($request), $this->sale->items);
        }

        return $result;
    }
}
