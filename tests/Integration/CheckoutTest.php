<?php

namespace Tests\Integration;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Cash\Contracts\PaymentMethods;
use Modules\Core\Contracts\OperationRejected;
use Modules\Core\Contracts\SecurityEvent;
use Modules\Sales\Contracts\DiscountApproval;
use Modules\Sales\Contracts\OpenSale;
use Modules\Sales\Contracts\SaleCheckout;
use Modules\Sales\Contracts\SaleQuery;
use Modules\Sales\Contracts\SalesOperations;
use Modules\Sales\Domain\SalesDependencyUnavailable;
use Modules\Sales\Domain\SaleState;
use Modules\Sales\Domain\SaleType;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CheckoutSqlServerTestCase;

class CheckoutTest extends CheckoutSqlServerTestCase
{
    public static function methods(): array
    {
        return array_map(fn ($code) => [$code], ['cash', 'card', 'transfer', 'other', 'mixed']);
    }

    #[DataProvider('methods')]
    public function test_checkout_confirms_exact_historical_payments_and_replay(string $code): void
    {
        $sale = $this->sale($code === 'mixed');
        $payments = $code === 'mixed' ? [$this->payment('cash', '80', '100'), $this->payment('card', '100')] : [$this->payment($code)];
        $key = (string) Str::uuid();
        $checkout = app(SaleCheckout::class);
        $closed = $checkout->confirm($this->actor, $sale->sale->id, $payments, $key, $this->revision($sale));
        $this->assertSame('closed', $closed->sale->state->value);
        $this->assertSame($this->actor, $closed->sale->closedBy);
        $this->assertMatchesRegularExpression('/\A[0-9]{8}0001\z/', $closed->sale->folio);
        $this->assertEquals($sale->items, $closed->items);
        $this->assertCount(count($payments), $closed->payments);
        $this->assertEquals($closed, $checkout->confirm($this->actor, $sale->sale->id, $payments, $key, $this->revision($sale)));
        app(PaymentMethods::class)->setActive($this->actor, $this->methodIds['cash'], false);
        $this->assertEquals($closed, $checkout->confirm($this->actor, $sale->sale->id, $payments, $key, $this->revision($sale)));
        $this->assertEquals($closed, app(SalesOperations::class)->sale($this->actor, $sale->sale->id));
        $cash = $code === 'cash' ? '180.0000' : ($code === 'mixed' ? '80.0000' : '0.0000');
        $this->assertSame($cash, $this->funds()['confirmed_sales_cash']);
        $this->assertSame(0, $this->database->table('cash_movements')->count());
        $this->assertSame(count($payments), $this->database->table('sales_payments')->count());
        $this->assertSame(1, $this->database->table('audit_security_events')->where('event_type', 'sales.checkout.confirmed')->count());
        $this->assertSame(0, app(SalesOperations::class)->sales($this->actor, new SaleQuery(state: SaleState::Open))->total);
        if ($code === 'mixed') {
            $this->assertFalse(app(SalesOperations::class)->table($this->actor, $sale->sale->tableId)->isOccupied);
        }
        $this->assertSame(1, app(SalesOperations::class)->sales($this->actor, new SaleQuery(folio: $closed->sale->folio))->total);
        try {
            $checkout->confirm($this->actor, $sale->sale->id, $payments, (string) Str::uuid(), $this->revision($closed));
            $this->fail('Duplicate close');
        } catch (OperationRejected $e) {
            $this->assertSame('CONFLICT', $e->apiCode->value);
        }
    }

    public function test_global_daily_folios_follow_business_day_not_utc_and_rollback_is_atomic(): void
    {
        $checkout = app(SaleCheckout::class);
        $folios = [];
        foreach (['2026-11-18T05:59:59Z', '2026-11-18T05:59:59Z', '2026-11-18T06:00:00Z'] as $time) {
            CarbonImmutable::setTestNow($time);
            $sale = $this->sale();
            $folios[] = $checkout->confirm($this->actor, $sale->sale->id, [$this->payment()], (string) Str::uuid(), $this->revision($sale))->sale->folio;
        }
        $this->assertSame(['202611170001', '202611170002', '202611180001'], $folios);
        $sale = $this->sale();
        $key = (string) Str::uuid();
        $fail = true;
        Event::listen(SecurityEvent::class, function (SecurityEvent $e) use (&$fail): void {
            if ($fail && $e->type === 'sales.checkout.confirmed') {
                throw new \RuntimeException('Fixture audit failure');
            }
        });
        try {
            $checkout->confirm($this->actor, $sale->sale->id, [$this->payment()], $key, $this->revision($sale));
            $this->fail('Expected audit failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('Fixture audit failure', $e->getMessage());
        }
        $this->assertSame(3, $this->database->table('sales_payments')->count());
        $this->assertSame(1, (int) $this->database->table('sales_daily_sequences')->where('business_date', '2026-11-18')->value('last_value'));
        $this->assertSame('open', $this->database->table('sales_sales')->where('id', $sale->sale->id)->value('status'));
        $fail = false;
        $this->assertSame('202611180002', $checkout->confirm($this->actor, $sale->sale->id, [$this->payment()], $key, $this->revision($sale))->sale->folio);
    }

    public static function invalid(): array
    {
        return [['under'], ['over'], ['negative'], ['insufficient'], ['empty'], ['inactive'], ['paused'], ['closed_session'], ['stale'], ['corrupt_total'], ['no_lines']];
    }

    #[DataProvider('invalid')]
    public function test_invalid_checkout_never_creates_partial_financial_effects(string $case): void
    {
        $sale = $case === 'no_lines' ? app(SalesOperations::class)->open($this->actor, new OpenSale(SaleType::Counter)) : $this->sale();
        $payments = [$this->payment()];
        $revision = $this->revision($sale);
        if ($case === 'under') {
            $payments[0]['applied_amount'] = '179';
        }
        if ($case === 'over') {
            $payments[0]['applied_amount'] = '181';
        }
        if ($case === 'negative') {
            $payments[0]['applied_amount'] = '-1';
        }
        if ($case === 'insufficient') {
            $payments[0]['received_cash'] = '100';
        }
        if ($case === 'empty') {
            $payments = [];
        }
        if ($case === 'inactive') {
            app(PaymentMethods::class)->setActive($this->actor, $this->methodIds['cash'], false);
        }
        if ($case === 'paused') {
            $sale = app(SalesOperations::class)->pause($this->actor, $sale->sale->id);
            $revision = $this->revision($sale);
        }
        if ($case === 'closed_session') {
            $this->closeFixture();
        }
        if ($case === 'stale') {
            app(SalesOperations::class)->editNote($this->actor, $sale->sale->id, 'Changed');
        }
        if ($case === 'corrupt_total') {
            $this->database->table('sales_sales')->where('id', $sale->sale->id)->update(['subtotal' => '181', 'total' => '181']);
            $sale = app(SalesOperations::class)->sale($this->actor, $sale->sale->id);
            $revision = $this->revision($sale);
        }
        try {
            app(SaleCheckout::class)->confirm($this->actor, $sale->sale->id, $payments, (string) Str::uuid(), $revision);
            $this->fail('Invalid checkout accepted');
        } catch (\Throwable $e) {
            $this->assertTrue($e instanceof OperationRejected || $e instanceof ValidationException, $e::class.': '.$e->getMessage());
        }
        $this->assertSame(0, $this->database->table('sales_payments')->count());
        $this->assertSame(0, $this->database->table('sales_daily_sequences')->count());
        $this->assertNull($this->database->table('sales_sales')->where('id', $sale->sale->id)->value('folio'));
        $this->assertSame(0, $this->database->table('audit_security_events')->where('event_type', 'sales.checkout.confirmed')->count());
    }

    public function test_zero_total_requires_prior_authorization_and_creates_no_artificial_payment(): void
    {
        $sale = $this->sale();
        $this->database->table('sales_sales')->where('id', $sale->sale->id)->update(['discount_amount' => '180', 'total' => '0']);
        $sale = app(SalesOperations::class)->sale($this->actor, $sale->sale->id);
        try {
            app(SaleCheckout::class)->confirm($this->actor, $sale->sale->id, [], (string) Str::uuid(), $this->revision($sale));
            $this->fail('Missing approval');
        } catch (SalesDependencyUnavailable) {
            $this->addToAssertionCount(1);
        }
        $this->app->instance(DiscountApproval::class, new class implements DiscountApproval
        {
            public function requireApproved(string $actorId, string $saleId, string $subtotal, string $discount): void
            {
                if ($subtotal !== '180.0000' || $discount !== $subtotal) {
                    throw new \LogicException('Invalid authorized fixture');
                }
            }
        });
        $closed = app(SaleCheckout::class)->confirm($this->actor, $sale->sale->id, [], (string) Str::uuid(), $this->revision($sale));
        $this->assertSame('0.0000', $closed->sale->total);
        $this->assertNotNull($closed->sale->folio);
        $this->assertSame([], $closed->payments);
        $this->assertSame('100.0000', $this->funds()['expected_cash']);
    }
}
