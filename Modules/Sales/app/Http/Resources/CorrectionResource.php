<?php

namespace Modules\Sales\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Solo recibe el resultado público saneado del caso de uso, nunca filas SQL. */
class CorrectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return array_intersect_key($this->resource, array_flip([
            'id', 'sale_id', 'cash_session_id', 'folio', 'status', 'reopened_by', 'reopened_at', 'observation', 'confirmed_by',
            'confirmed_at', 'revision', 'original', 'proposed', 'sale', 'changes', 'financial',
        ]));
    }
}
