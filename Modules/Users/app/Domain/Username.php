<?php

namespace Modules\Users\Domain;

final class Username
{
    public static function normalize(string $value): string
    {
        return strtolower(trim($value));
    }
}
