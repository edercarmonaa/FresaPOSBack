<?php

namespace Modules\Sales\Domain;

use InvalidArgumentException;

/** Decimal(19,4). Los 19 dígitos escalados pueden superar PHP_INT_MAX. */
final readonly class Amount
{
    private function __construct(public string $value) {}

    public static function from(mixed $value): self
    {
        if (! is_string($value) || preg_match('/\A(?:0|[1-9][0-9]{0,14})\.[0-9]{4}\z/', $value) !== 1) {
            throw new InvalidArgumentException('El importe requiere un decimal canónico no negativo de escala cuatro.');
        }

        return new self($value);
    }

    public function add(self $other): self
    {
        $a = strrev($this->digits());
        $b = strrev($other->digits());
        $result = '';
        $carry = 0;
        for ($i = 0; $i < max(strlen($a), strlen($b)); $i++) {
            $n = (int) ($a[$i] ?? '0') + (int) ($b[$i] ?? '0') + $carry;
            $result .= (string) ($n % 10);
            $carry = intdiv($n, 10);
        }
        if ($carry) {
            $result .= (string) $carry;
        }

        return self::scaled(strrev($result));
    }

    public function multiply(mixed $quantity): self
    {
        if (! is_int($quantity) || $quantity < 1 || $quantity > 2147483647) {
            throw new InvalidArgumentException('La cantidad debe ser positiva y compatible con SQL int.');
        }
        $result = '';
        $carry = 0;
        foreach (str_split(strrev($this->digits())) as $digit) {
            $n = (int) $digit * $quantity + $carry;
            $result .= (string) ($n % 10);
            $carry = intdiv($n, 10);
        }
        if ($carry) {
            $result .= strrev((string) $carry);
        }

        return self::scaled(strrev($result));
    }

    public function subtract(self $other): self
    {
        $a = $this->digits();
        $b = $other->digits();
        if (strlen($a) < strlen($b) || (strlen($a) === strlen($b) && strcmp($a, $b) < 0)) {
            throw new InvalidArgumentException('El total no puede ser negativo.');
        }
        $a = strrev($a);
        $b = strrev($b);
        $borrow = 0;
        $result = '';
        for ($i = 0; $i < strlen($a); $i++) {
            $n = (int) $a[$i] - (int) ($b[$i] ?? '0') - $borrow;
            $borrow = $n < 0 ? 1 : 0;
            $result .= (string) (($n + 10) % 10);
        }

        return self::scaled(strrev($result));
    }

    private function digits(): string
    {
        return ltrim(str_replace('.', '', $this->value), '0') ?: '0';
    }

    private static function scaled(string $digits): self
    {
        $digits = ltrim($digits, '0') ?: '0';
        if (strlen($digits) > 19) {
            throw new InvalidArgumentException('El importe excede decimal(19,4).');
        }
        $digits = str_pad($digits, 5, '0', STR_PAD_LEFT);

        return self::from(substr($digits, 0, -4).'.'.substr($digits, -4));
    }
}
