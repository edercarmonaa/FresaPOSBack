<?php

namespace Modules\Cash\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Cash\Contracts\PaymentMethodSnapshot;

/** @mixin PaymentMethodSnapshot */
final class PaymentMethodResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'code' => $this->code, 'name' => $this->name, 'is_active' => $this->isActive, 'display_order' => $this->displayOrder];
    }
}
