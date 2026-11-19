<?php

namespace Modules\Core\Contracts;

use InvalidArgumentException;

/** Decimal(19,4). Los 19 dígitos escalados pueden superar PHP_INT_MAX. */
final readonly class DecimalAmount
{
    private function __construct(public string $value) {}

    public static function parse(mixed $value): self
    {
        if (! is_string($value) || preg_match('/\A(?:0|[1-9][0-9]{0,14})(?:\.[0-9]{1,4})?\z/', $value) !== 1) {
            throw new InvalidArgumentException('Usa texto decimal no negativo, hasta 15 enteros y cuatro decimales.');
        }
        $parts = explode('.', $value, 2);

        return self::from($parts[0].'.'.str_pad($parts[1] ?? '', 4, '0'));
    }

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

        return self::scaled($this->multipliedDigits($quantity));
    }

    public function percentage(mixed $percentage): self
    {
        if (! is_int($percentage) || $percentage < 1 || $percentage > 100) {
            throw new InvalidArgumentException('Usa un porcentaje entero de 1 a 100.');
        }
        // El producto intermedio puede tener 21 dígitos. Dividir antes de validar
        // decimal(19,4), y redondear una sola vez al final, mitades hacia arriba.
        $digits = str_pad($this->multipliedDigits($percentage), 3, '0', STR_PAD_LEFT);
        $result = self::scaled(substr($digits, 0, -2));

        return (int) substr($digits, -2) >= 50 ? $result->add(self::from('0.0001')) : $result;
    }

    private function multipliedDigits(int $quantity): string
    {
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

        return strrev($result);
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
