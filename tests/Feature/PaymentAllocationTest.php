<?php

namespace Tests\Feature;

use InvalidArgumentException;
use Modules\Cash\Contracts\PaymentMethodSnapshot;
use Modules\Core\Contracts\DecimalAmount;
use Modules\Sales\Domain\PaymentAllocation;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PaymentAllocationTest extends TestCase
{
    private function methods(bool $active = true): array
    {
        return array_map(fn ($code) => new PaymentMethodSnapshot($code === 'cash' ? '01arz3ndektsv4rrffq69g5fav' : ($code === 'card' ? '01arz3ndektsv4rrffq69g5faw' : ($code === 'transfer' ? '01arz3ndektsv4rrffq69g5fax' : '01arz3ndektsv4rrffq69g5fay')), $code, $code, $active, 0), ['cash', 'card', 'transfer', 'other']);
    }

    public function test_cash_change_and_mixed_payments_are_exact_and_have_no_persistence(): void
    {
        $methods = $this->methods();
        $plan = PaymentAllocation::calculate('180', $methods, [['payment_method_id' => $methods[0]->id, 'applied_amount' => '180', 'received_cash' => '200']]);
        $this->assertSame('20.0000', $plan->payments[0]->changeCash);
        $this->assertSame('180.0000', $plan->cashApplied);
        $this->assertSame('0.0000', $plan->remaining);
        $plan = PaymentAllocation::calculate('350.0000', $methods, [['payment_method_id' => $methods[0]->id, 'applied_amount' => '150', 'received_cash' => '200'], ['payment_method_id' => $methods[1]->id, 'applied_amount' => '200']]);
        $this->assertSame('350.0000', $plan->applied);
        $this->assertSame('150.0000', $plan->cashApplied);
        $this->assertSame('50.0000', $plan->payments[0]->changeCash);
        $this->assertNull($plan->payments[1]->changeCash);
        $exact = PaymentAllocation::calculate('0.0003', $methods, [['payment_method_id' => $methods[0]->id, 'applied_amount' => '0.0003', 'received_cash' => '0.0003']]);
        $this->assertSame('0.0000', $exact->payments[0]->changeCash);
    }

    public function test_transfer_other_and_partial_plan_have_explicit_rules(): void
    {
        $m = $this->methods();
        foreach ([2, 3] as $index) {
            $plan = PaymentAllocation::calculate('25', $m, [['payment_method_id' => $m[$index]->id, 'applied_amount' => '25', 'reference' => 'RECEIPT-ABC123']], allowOther: true);
            $this->assertSame('0.0000', $plan->cashApplied);
            $this->assertNull($plan->payments[0]->receivedCash);
        }
        $partial = PaymentAllocation::calculate('100', $m, [['payment_method_id' => $m[1]->id, 'applied_amount' => '60']], requireComplete: false);
        $this->assertSame('40.0000', $partial->remaining);
        $this->assertSame('999999999999999.9999', DecimalAmount::from('999999999999999.9998')->add(DecimalAmount::from('0.0001'))->value);
    }

    public static function invalidPlans(): array
    {
        return [
            ['negative', '-1', null, null], ['zero', '0', null, null], ['float', 1.5, null, null], ['exponent', '1e2', null, null], ['scale', '1.00001', null, null], ['overflow', '1000000000000000', null, null],
            ['insufficient', '50', null, null], ['excess', '101', null, null], ['cash_short', '100', 'cash', '99'], ['card_cash', '100', 'card', '100'], ['transfer_ref', '100', 'transfer', null], ['other_denied', '100', 'other', null], ['bank_data', '100', 'card', null], ['unknown_fields', '100', 'card', null], ['inactive', '100', 'card', null],
        ];
    }

    #[DataProvider('invalidPlans')]
    public function test_invalid_financial_plans_are_rejected(string $case, mixed $amount, ?string $code, ?string $received): void
    {
        $methods = $this->methods($case !== 'inactive');
        $method = $methods[array_search($code ?? 'card', ['cash', 'card', 'transfer', 'other'], true)];
        $payment = ['payment_method_id' => $method->id, 'applied_amount' => $amount];
        if ($received !== null) {
            $payment['received_cash'] = $received;
        }
        if ($case === 'bank_data') {
            $payment['reference'] = '4111111111111111';
        }
        if ($case === 'unknown_fields') {
            $payment['change_cash'] = '100';
        }
        $this->expectException(InvalidArgumentException::class);
        PaymentAllocation::calculate('100', $methods, [$payment]);
    }

    public function test_decimal_overflow_and_inactive_unknown_methods_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        DecimalAmount::from('999999999999999.9999')->add(DecimalAmount::from('0.0001'));
    }

    public function test_bank_number_with_separators_is_not_a_payment_reference(): void
    {
        $methods = $this->methods();
        $this->expectException(InvalidArgumentException::class);
        PaymentAllocation::calculate('100', $methods, [['payment_method_id' => $methods[1]->id, 'applied_amount' => '100', 'reference' => '4111-1111-1111-1111']]);
    }
}
