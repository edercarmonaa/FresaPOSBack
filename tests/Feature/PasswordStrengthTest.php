<?php

namespace Tests\Feature;

use Modules\Users\Domain\PasswordStrength;
use Modules\Users\Domain\Username;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PasswordStrengthTest extends TestCase
{
    public static function passwords(): array
    {
        return [['Aa1!aaaa', true], ['Aa1!aaa', false], ['aa1!aaaa', false], ['AA1!AAAA', false], ['Aa!!aaaa', false], ['Aa11aaaa', false], [str_repeat('a', 70).'A1!', false], ['Aa1 aaaa', false], ['Aa1!éé', false], ['Áa1!éééé', true], ["Aa1!a\0aa", false], ['Aa12345é', false]];
    }

    #[DataProvider('passwords')]
    public function test_password_policy(string $password, bool $expected): void
    {
        $this->assertSame($expected, PasswordStrength::accepts($password));
    }

    public function test_username_normalization_is_deterministic(): void
    {
        $this->assertSame('john.doe', Username::normalize('  John.DOE  '));
    }
}
