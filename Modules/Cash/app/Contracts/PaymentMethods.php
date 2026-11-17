<?php

namespace Modules\Cash\Contracts;

interface PaymentMethods
{
    /** @return list<PaymentMethodSnapshot> */
    public function available(string $actorId): array;

    public function find(string $actorId, string $id): PaymentMethodSnapshot;

    public function setActive(string $actorId, string $id, bool $active): PaymentMethodSnapshot;
}
