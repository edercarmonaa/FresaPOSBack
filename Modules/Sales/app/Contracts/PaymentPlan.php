<?php

namespace Modules\Sales\Contracts;

final readonly class PaymentPlan
{
    /** @param list<PreparedPayment> $payments */
    public function __construct(public string $total, public string $applied, public string $remaining, public string $cashApplied, public array $payments) {}
}
