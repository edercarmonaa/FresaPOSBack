<?php

namespace Tests\Fixtures\Sales;

/** Conserva etiquetas legibles de fixtures históricas y las transforma en UUID v4 válidos. */
final class IdempotencyKeys
{
    public static function fromLabel(string $label): string
    {
        $hex = substr(hash('sha256', $label), 0, 32);
        $hex[12] = '4';
        $hex[16] = '8';

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
    }
}
