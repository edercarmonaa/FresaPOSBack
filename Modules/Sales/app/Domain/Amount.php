<?php

namespace Modules\Sales\Domain;

use Modules\Core\Contracts\DecimalAmount;

/** Conserva el contrato Sales con la aritmética decimal compartida. */
final readonly class Amount
{
    private function __construct(public string $value) {}

    public static function from(mixed $value): self
    {
        return new self(DecimalAmount::from($value)->value);
    }

    public function add(self $other): self
    {
        return self::from(DecimalAmount::from($this->value)->add(DecimalAmount::from($other->value))->value);
    }

    public function subtract(self $other): self
    {
        return self::from(DecimalAmount::from($this->value)->subtract(DecimalAmount::from($other->value))->value);
    }

    public function multiply(mixed $quantity): self
    {
        return self::from(DecimalAmount::from($this->value)->multiply($quantity)->value);
    }
}
