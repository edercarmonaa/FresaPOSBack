<?php

namespace Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Sales\Application\SalesRevision;
use Modules\Sales\Contracts\DiningTableSnapshot;

class TableResource extends JsonResource
{
    public function __construct(private readonly DiningTableSnapshot $table)
    {
        parent::__construct($table);
    }

    public function toArray(Request $request): array
    {
        $table = $this->table;

        return ['id' => $table->id, 'name' => $table->name, 'is_active' => $table->isActive,
            'display_order' => $table->displayOrder, 'availability' => ! $table->isActive ? 'inactive' : ($table->isOccupied ? 'occupied' : 'free'),
            'active_sale_id' => $table->activeSaleId, 'revision' => SalesRevision::of($table)];
    }
}
