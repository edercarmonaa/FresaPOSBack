<?php

namespace Modules\Cash\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Cash\Contracts\SessionSnapshot;

/** @mixin SessionSnapshot */
final class SessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'register' => (new RegisterResource($this->register))->resolve($request),
            'opened_by' => $this->openedBy, 'status' => $this->status, 'opening_fund' => $this->openingFund,
            'opened_at' => $this->openedAt, 'closed_at' => $this->closedAt, 'closed_by' => $this->closedBy];
    }
}
