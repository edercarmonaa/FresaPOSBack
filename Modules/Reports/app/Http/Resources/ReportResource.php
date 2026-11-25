<?php

namespace Modules\Reports\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Reports\Contracts\ReportData;

/** @mixin ReportData */
final class ReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return $this->resource->jsonSerialize();
    }
}
