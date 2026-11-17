<?php

namespace Modules\Cash\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Cash\Contracts\CashMovementSnapshot;

/** @mixin CashMovementSnapshot */
final class CashMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return $this->resource->toArray();
    }
}
