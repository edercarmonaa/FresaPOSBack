<?php

namespace Modules\Sales\Contracts;

final readonly class PreparedPayment
{
    public function __construct(public string $paymentMethodId, public string $methodCode, public string $appliedAmount, public ?string $receivedCash, public ?string $changeCash, public ?string $reference) {}
}
