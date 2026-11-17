<?php

namespace Tests\Feature;

use Illuminate\Validation\ValidationException;
use Modules\Cash\Domain\OpeningFund;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CashOpeningFundTest extends TestCase
{
    public static function validFunds(): array
    {
        return [['0', '0.0000'], ['100.25', '100.2500'], ['0.0001', '0.0001'], ['999999999999999.9999', '999999999999999.9999']];
    }

    #[DataProvider('validFunds')]
    public function test_exact_decimal_normalization(string $input, string $expected): void
    {
        $this->assertSame($expected, OpeningFund::normalize($input));
    }

    public static function invalidFunds(): array
    {
        return [[-1], ['-1'], [1.25], [1], [true], [null], [[]], ['1e3'], ['1,50'], [' 1'], ['01.00'], ['1.12345'], ['1000000000000000'], ['NaN']];
    }

    #[DataProvider('invalidFunds')]
    public function test_invalid_funds_rejected(mixed $input): void
    {
        // ValidationException uses the application translator.
        $this->expectException(ValidationException::class);
        OpeningFund::normalize($input);
    }
}
