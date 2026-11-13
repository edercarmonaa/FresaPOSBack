<?php

namespace Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Sales\Contracts\SaleItemSnapshot;

class SaleItemResource extends JsonResource
{
    public function __construct(private readonly SaleItemSnapshot $item)
    {
        parent::__construct($item);
    }

    public function toArray(Request $request): array
    {
        $item = $this->item;

        return ['id' => $item->id, 'product_id' => $item->productId, 'name' => $item->name,
            'category' => ['id' => $item->categoryId, 'name' => $item->categoryName], 'unit_price' => $item->unitPrice,
            'quantity' => $item->quantity, 'note' => $item->note, 'line_total' => $item->lineTotal,
            'position' => $item->position, 'removed_at' => $item->removedAt];
    }
}
