<?php

namespace Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReasonResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return array_intersect_key((array) $this->resource, array_flip(['id', 'code', 'name', 'description', 'operation', 'is_active', 'display_order', 'revision']));
    }
}
