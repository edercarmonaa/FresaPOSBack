<?php

namespace Tests\Feature;

use Modules\Core\Contracts\DecimalAmount;
use Modules\Sales\Contracts\DiscountProposal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DiscountArithmeticTest extends TestCase
{
    public static function amounts(): array
    {
        return [['250.0000', 20, '50.0000'], ['180.0000', 100, '180.0000'], ['0.0050', 1, '0.0001'], ['0.0049', 1, '0.0000'], ['0.0001', 50, '0.0001'], ['0.0001', 49, '0.0000'], ['999999999999999.9999', 100, '999999999999999.9999'], ['999999999999999.9999', 99, '989999999999999.9999'], ['0.0000', 100, '0.0000']];
    }

    #[DataProvider('amounts')]
    public function test_half_up_at_scale_four_without_overflow_in_intermediate_product(string $subtotal, int $percentage, string $expected): void
    {
        $this->assertSame($expected, DecimalAmount::from($subtotal)->percentage($percentage)->value);
    }

    public static function invalid(): array
    {
        return [['percent', 0], ['percent', 101], ['percent', -1], ['percent', 1.5], ['percent', '20'], ['percent', true], ['percentage', 20], ['fixed', '0'], ['fixed', '-1'], ['fixed', 20.0], ['fixed', '1.00001'], ['fixed', '1e2']];
    }

    #[DataProvider('invalid')]
    public function test_invalid_proposals_do_not_normalize_unsafe_values(string $type, mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DiscountProposal($type, $value);
    }

    public function test_fixed_proposal_normalizes_decimal_text_only(): void
    {
        $this->assertSame('75.0000', (new DiscountProposal('fixed', '75'))->value);
    }
}
