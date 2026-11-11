<?php

namespace Modules\Users\Domain;

final class PasswordStrength
{
    public static function accepts(#[\SensitiveParameter] string $password): bool
    {
        return preg_match('//u', $password) === 1
            && mb_strlen($password, 'UTF-8') >= 8 && strlen($password) <= 72
            && preg_match('/\p{Lu}/u', $password) === 1
            && preg_match('/\p{Ll}/u', $password) === 1
            && preg_match('/[0-9]/', $password) === 1
            && preg_match('/[\p{P}\p{S}]/u', $password) === 1
            && preg_match('/\p{C}/u', $password) === 0;
    }
}
