<?php

namespace Tests\Integration;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Modules\Cash\Contracts\CashMovements;
use Modules\Catalog\Contracts\CatalogAdministration;
use Modules\Core\Contracts\OperationRejected;
use Modules\Sales\Contracts\SaleCheckout;
use Modules\Sales\Contracts\SalesOperations;
use Modules\Sales\Domain\SalesConflict;
use Tests\Support\CheckoutSqlServerTestCase;

class CheckoutIntegrityTest extends CheckoutSqlServerTestCase
{
    private function rejected(callable $write): void
    {
        try {
            $write();
            $this->fail('Financial mutation accepted');
        } catch (QueryException $e) {
            $this->assertContains((int) ($e->errorInfo[1] ?? 0), [547, 2601, 2627, 51001, 51002, 51003]);
        }
    }

    public function test_closed_sale_lines_and_payments_are_immutable_and_historical_prices_are_retained(): void
    {
        $sale = $this->sale();
        app(CatalogAdministration::class)->updateProduct($this->actor, $this->productId, ['name' => 'New catalog name', 'category_id' => $sale->items[0]->categoryId, 'price' => '999.0000']);
        $closed = app(SaleCheckout::class)->confirm($this->actor, $sale->sale->id, [$this->payment()], (string) Str::uuid(), $this->revision($sale));
        $this->assertSame('180.0000', $closed->sale->total);
        $this->assertSame('180.0000', $closed->items[0]->unitPrice);
        $this->assertSame('Checkout product', $closed->items[0]->name);
        foreach ([['status' => 'reopened'], ['folio' => '202611170099'], ['total' => '179', 'discount_amount' => '1']] as $change) {
            $this->rejected(fn () => $this->database->table('sales_sales')->where('id', $sale->sale->id)->update($change));
        }
        $this->rejected(fn () => $this->database->table('sales_items')->where('sale_id', $sale->sale->id)->update(['note' => 'Tampered']));
        $this->rejected(fn () => $this->database->table('sales_items')->where('sale_id', $sale->sale->id)->delete());
        $this->rejected(fn () => $this->database->table('sales_payments')->where('sale_id', $sale->sale->id)->update(['reference' => 'Tampered']));
        $this->rejected(fn () => $this->database->table('sales_payments')->where('sale_id', $sale->sale->id)->delete());
        $this->assertEquals($closed, app(SalesOperations::class)->sale($this->actor, $sale->sale->id));
        try {
            app(SalesOperations::class)->editNote($this->actor, $sale->sale->id, 'Forbidden');
            $this->fail('Closed edit');
        } catch (SalesConflict) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_prepared_payments_are_excluded_from_balance_and_confirmed_cash_can_fund_a_manual_withdrawal(): void
    {
        $sale = $this->sale();
        $method = $this->methodIds['cash'];
        $this->database->table('sales_payments')->insert(['id' => strtolower((string) Str::ulid()), 'sale_id' => $sale->sale->id, 'cash_session_id' => $this->sessionId, 'payment_method_id' => $method, 'method_code' => 'cash', 'applied_amount' => '5', 'received_cash' => '5', 'change_cash' => '0', 'created_by' => $this->actor, 'status' => 'prepared', 'created_at' => now()->utc(), 'updated_at' => now()->utc()]);
        $this->assertSame('100.0000', $this->funds()['expected_cash']);
        app(SaleCheckout::class)->confirm($this->actor, $sale->sale->id, [$this->payment()], (string) Str::uuid(), $this->revision($sale));
        $this->assertSame('280.0000', $this->funds()['expected_cash']);
        app(CashMovements::class)->record($this->actor, $this->sessionId, 'cash_out', '250', 'Retiro después de venta', (string) Str::uuid());
        $this->assertSame('30.0000', $this->funds()['expected_cash']);
        $this->assertSame('180.0000', $this->funds()['confirmed_sales_cash']);
        $this->assertSame(1, $this->database->table('cash_movements')->count());
    }

    public function test_folio_limit_and_invalid_timezone_abort_without_payments_or_close(): void
    {
        CarbonImmutable::setTestNow('2026-11-17T20:00:00Z');
        $sale = $this->sale();
        $this->database->table('sales_daily_sequences')->insert(['business_date' => '2026-11-17', 'last_value' => 9999]);
        try {
            app(SaleCheckout::class)->confirm($this->actor, $sale->sale->id, [$this->payment()], (string) Str::uuid(), $this->revision($sale));
            $this->fail('Daily limit');
        } catch (OperationRejected $e) {
            $this->assertSame('CONFLICT', $e->apiCode->value);
        }
        config(['pos.business_timezone' => 'Browser/Arbitrary']);
        try {
            app(SaleCheckout::class)->confirm($this->actor, $sale->sale->id, [$this->payment()], (string) Str::uuid(), $this->revision($sale));
            $this->fail('Invalid timezone');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('POS_BUSINESS_TIMEZONE', $e->getMessage());
        }
        $this->assertSame(0, $this->database->table('sales_payments')->count());
        $this->assertSame(9999, (int) $this->database->table('sales_daily_sequences')->value('last_value'));
        $this->assertNull($this->database->table('sales_sales')->value('folio'));
    }

    public function test_new_sql_schema_retains_trusted_foreign_keys_and_folio_uniqueness(): void
    {
        $fks = $this->database->select("SELECT name FROM sys.foreign_keys WHERE name IN ('sales_sales_closed_by_foreign','sales_payment_original_session') AND is_disabled=0 AND is_not_trusted=0 AND delete_referential_action=0");
        $this->assertCount(2, $fks);
        $sale = $this->sale();
        $closed = app(SaleCheckout::class)->confirm($this->actor, $sale->sale->id, [$this->payment()], (string) Str::uuid(), $this->revision($sale));
        $other = $this->sale();
        $this->rejected(fn () => $this->database->table('sales_sales')->where('id', $other->sale->id)->update(['status' => 'closed', 'closed_at' => now()->utc(), 'closed_by' => $this->actor, 'folio' => $closed->sale->folio]));
        $this->assertSame('open', $this->database->table('sales_sales')->where('id', $other->sale->id)->value('status'));
    }
}
