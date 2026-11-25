<?php

namespace Tests\Integration;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Modules\Cash\Contracts\CashMovements;
use Modules\Catalog\Contracts\CatalogAdministration;
use Modules\Reports\Contracts\OperationalReports;
use Modules\Reports\Contracts\ReportData;
use Modules\Sales\Contracts\DiscountProposal;
use Modules\Sales\Contracts\SaleCheckout;
use Modules\Sales\Contracts\SalesOperations;
use Modules\Tickets\Contracts\SaleReceipts;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ReceiptsSqlServerTestCase;

class OperationalReportsTest extends ReceiptsSqlServerTestCase
{
    protected function report(string $type, array $filters = []): ReportData
    {
        return app(OperationalReports::class)->read($this->actor, $type, $filters);
    }

    public static function reportTypes(): array
    {
        return array_map(fn ($name) => [$name], ['summary', 'sales', 'products', 'payments', 'cash-movements', 'cash-sessions', 'cash-reconciliations', 'discounts', 'corrections']);
    }

    #[DataProvider('reportTypes')]
    public function test_all_nine_reports_are_read_only_and_restore_connection_options(string $type): void
    {
        $this->settledSale();
        $before = $this->financialState();
        $options = $this->database->select('DBCC USEROPTIONS');
        $data = $this->report($type);
        $this->assertSame($type, $data->report);
        if ($type === 'sales') {
            $this->assertSame('2026-11-17T18:00:00.000000Z', $data->items[0]['created_at']);
            $this->assertSame('2026-11-17T18:00:00.000000Z', $data->items[0]['closed_at']);
        }
        $this->assertSame('America/Mexico_City', $data->timezone);
        $this->assertContains($data->isolation, ['SNAPSHOT', 'SERIALIZABLE']);
        $this->assertSame($before, $this->financialState());
        $this->assertEquals($options, $this->database->select('DBCC USEROPTIONS'));
        $this->assertSame(0, $this->database->transactionLevel());
    }

    public function test_unpaid_cancelled_and_reprinted_sales_do_not_inflate_confirmed_income(): void
    {
        $first = $this->settledSale();
        $this->settledSale(true);
        $this->sale();
        app(SalesOperations::class)->pause($this->actor, $this->sale()->sale->id);
        $cancel = $this->sale();
        $this->control($cancel, 'cancel', 'QA_CANCEL');
        app(CashMovements::class)->record($this->actor, $this->sessionId, 'cash_in', '50', 'Entrada manual fixture', (string) Str::uuid());
        app(CashMovements::class)->record($this->actor, $this->sessionId, 'cash_out', '10', 'Salida manual fixture', (string) Str::uuid());
        $receipt = $this->receipt($first->sale->id);
        app(SaleReceipts::class)->reprint($this->actor, $first->sale->id, $receipt->revision, (string) Str::uuid());
        $sales = $this->report('sales');
        $summary = $this->report('summary');
        $this->assertSame(5, $sales->total);
        $this->assertSame(2, $summary->totals['paid_sales']);
        $this->assertSame('360.0000', $summary->totals['confirmed_income']);
        $this->assertSame('180.0000', $summary->totals['average_ticket']);
        $this->assertSame(1, $summary->totals['counter_sales']);
        $this->assertSame(1, $summary->totals['table_sales']);
        $this->assertSame(2, $summary->totals['units_sold']);
        $this->assertSame(0, $summary->totals['closed_cash_sessions']);
        $this->assertSame('40.0000', $this->report('cash-movements')->totals['manual_net']);
        $this->assertSame('0.0000', $this->report('payments')->totals['reconciliation_difference']);
        foreach (['open', 'paused', 'cancelled'] as $state) {
            $data = $this->report('sales', ['state' => $state]);
            $this->assertSame(1, $data->total);
            $this->assertSame(0, $data->totals['paid_sales']);
            $this->assertSame('0.0000', $data->totals['confirmed_income']);
        }
    }

    public static function paymentMethods(): array
    {
        return [['cash'], ['card'], ['transfer'], ['other']];
    }

    #[DataProvider('paymentMethods')]
    public function test_method_report_uses_applied_amounts_and_excludes_sensitive_references(string $method): void
    {
        $sale = $this->sale();
        app(SaleCheckout::class)->confirm($this->actor, $sale->sale->id, [$this->payment($method)], (string) Str::uuid(), $this->revision($sale));
        $data = $this->report('payments');
        $this->assertSame(1, $data->total);
        $this->assertSame($method, $data->items[0]['method']);
        $this->assertSame('180.0000', $data->items[0]['amount']);
        $this->assertSame('100.0000', $data->items[0]['share']);
        $this->assertSame('180.0000', $data->totals['payments_total']);
        $this->assertSame('0.0000', $data->totals['reconciliation_difference']);
        $this->assertStringNotContainsString('RECEIPT-ABC', json_encode($data, JSON_THROW_ON_ERROR));
        $this->assertArrayNotHasKey('received_cash', $data->items[0]);
    }

    public function test_mixed_payment_filter_keeps_all_components_of_its_sale_cohort(): void
    {
        $sale = $this->sale();
        app(SaleCheckout::class)->confirm($this->actor, $sale->sale->id, [$this->payment('cash', '80', '100'), $this->payment('card', '100', null)], (string) Str::uuid(), $this->revision($sale));
        $data = $this->report('payments', ['payment_method' => 'cash']);
        $this->assertSame(2, $data->total);
        $this->assertSame(['100.0000', '80.0000'], array_column($data->items, 'amount'));
        $this->assertSame('180.0000', $data->totals['confirmed_income']);
        $this->assertSame('180.0000', $data->totals['payments_total']);
        $this->assertSame('0.0000', $data->totals['reconciliation_difference']);
    }

    public static function discountCases(): array
    {
        return [['percent', 25, '45.0000', '135.0000'], ['fixed', '30.0000', '30.0000', '150.0000'], ['percent', 100, '180.0000', '0.0000'], ['fixed', '180.0000', '180.0000', '0.0000']];
    }

    #[DataProvider('discountCases')]
    public function test_confirmed_discounts_and_zero_sales_have_the_correct_counts_and_no_fake_payments(string $type, int|string $value, string $discount, string $total): void
    {
        $sale = $this->discount($this->sale(), new DiscountProposal($type, $value));
        app(SaleCheckout::class)->confirm($this->actor, $sale->sale->id, $total === '0.0000' ? [] : [$this->payment('cash', $total, $total)], (string) Str::uuid(), $this->revision($sale));
        $data = $this->report('discounts');
        $summary = $this->report('summary');
        $this->assertSame(1, $data->total);
        $this->assertSame($type, $data->items[0]['discount_type']);
        $this->assertSame($discount, $data->items[0]['discount_amount']);
        $this->assertSame($total, $summary->totals['confirmed_income']);
        $this->assertSame(1, $summary->totals['paid_sales']);
        $this->assertSame($total, $summary->totals['average_ticket']);
        $this->assertSame($total === '0.0000' ? 1 : 0, $summary->totals['zero_sales']);
        $this->assertSame($total === '0.0000' ? 0 : 1, $this->report('payments')->total);
        $this->assertSame($this->actor, $data->items[0]['authorizer_id']);
    }

    public function test_historical_product_names_categories_and_amounts_do_not_follow_catalog_changes(): void
    {
        $paid = $this->settledSale();
        app(CatalogAdministration::class)->updateProduct($this->actor, $this->productId, ['name' => 'Otro nombre actual', 'price' => '999.0000']);
        $data = $this->report('products', ['product_id' => $this->productId, 'category_id' => $paid->items[0]->categoryId]);
        $this->assertSame(1, $data->total);
        $this->assertSame('Checkout product', $data->items[0]['product_name']);
        $this->assertSame('Checkout fixture', $data->items[0]['category_name']);
        $this->assertSame(1, $data->items[0]['quantity']);
        $this->assertSame('180.0000', $data->items[0]['amount']);
        $this->assertSame('100.0000', $data->items[0]['share']);
    }

    public function test_active_correction_draft_is_not_income_and_confirmed_correction_keeps_one_sale_and_folio(): void
    {
        $sale = $this->settledSale();
        $row = $this->reopen($sale, 'Ajuste autorizado fixture');
        $row = $this->correction($row, 'edit', ['action' => 'quantity', 'item_id' => $sale->items[0]->id, 'quantity' => 2]);
        $active = $this->report('corrections');
        $this->assertNull($active->items[0]['after_total']);
        $this->assertNull($active->items[0]['difference']);
        $this->assertSame('180.0000', $this->report('summary')->totals['confirmed_income']);
        $this->assertSame(1, $this->report('products')->items[0]['quantity']);
        $this->assertStringNotContainsString('draft_snapshot', json_encode($active, JSON_THROW_ON_ERROR));
        $this->correction($row, 'confirm', ['payments' => [$this->payment('cash', '180', '200')]]);
        $data = $this->report('sales');
        $corrections = $this->report('corrections');
        $this->assertSame(1, $data->total);
        $this->assertSame($sale->sale->folio, $data->items[0]['folio']);
        $this->assertTrue($data->items[0]['corrected']);
        $this->assertSame('360.0000', $data->items[0]['total']);
        $this->assertSame('180.0000', $corrections->items[0]['difference']);
        $this->assertSame('360.0000', $this->report('payments')->totals['payments_total']);
        $this->assertSame(2, $this->report('products')->items[0]['quantity']);
    }

    public static function closingDifferences(): array
    {
        return [['280', '0.0000'], ['290', '10.0000'], ['270', '-10.0000']];
    }

    #[DataProvider('closingDifferences')]
    public function test_cash_blind_session_stays_empty_until_immutable_reconciliation_exists(string $counted, string $difference): void
    {
        $this->settledSale();
        $open = $this->report('cash-sessions');
        foreach (['expected_cash', 'counted_cash', 'difference'] as $field) {
            $this->assertNull($open->items[0][$field]);
        }
        $this->assertSame(0, $this->report('cash-reconciliations')->total);
        $this->closeCount($counted);
        $before = $this->financialState();
        $closed = $this->report('cash-reconciliations');
        $this->assertSame(1, $closed->total);
        $this->assertSame('100.0000', $closed->items[0]['opening_fund']);
        $this->assertSame('180.0000', $closed->items[0]['confirmed_sales_cash']);
        $this->assertSame('280.0000', $closed->items[0]['expected_cash']);
        $this->assertSame($difference, $closed->items[0]['difference']);
        $this->assertSame($difference, $this->report('summary')->totals['closing_differences']);
        $this->assertSame($before, $this->financialState());
    }

    public function test_empty_report_does_not_invent_an_average(): void
    {
        $data = $this->report('summary');
        $this->assertSame(0, $data->total);
        $this->assertSame(0, $data->totals['paid_sales']);
        $this->assertSame('0.0000', $data->totals['confirmed_income']);
        $this->assertNull($data->totals['average_ticket']);
        $this->assertNull($data->totals['average_discount']);
    }

    public function test_filters_select_the_whole_matching_cohort_and_empty_ids_do_not_expand_it(): void
    {
        $counter = $this->settledSale();
        $this->settledSale(true);
        $filters = ['employee_id' => strtoupper($this->actor), 'cash_session_id' => strtoupper($this->sessionId), 'sale_type' => 'counter', 'product_id' => strtoupper($this->productId), 'category_id' => $counter->items[0]->categoryId, 'payment_method' => 'cash', 'state' => 'closed'];
        $data = $this->report('sales', $filters);
        $this->assertSame(1, $data->total);
        $this->assertSame($counter->sale->id, $data->items[0]['sale_id']);
        $this->assertSame('180.0000', $data->totals['confirmed_income']);
        foreach (['employee_id', 'product_id', 'category_id', 'cash_session_id'] as $field) {
            $this->assertSame(0, $this->report('sales', [$field => strtolower((string) Str::ulid())])->total);
        }
        $this->assertSame(0, $this->report('sales', ['payment_method' => 'card'])->total);
    }

    public function test_business_midnight_is_end_exclusive_in_sql_and_in_the_daily_series(): void
    {
        $this->settledSale();
        CarbonImmutable::setTestNow('2026-11-18T05:59:59Z');
        $this->settledSale();
        CarbonImmutable::setTestNow('2026-11-18T06:00:00Z');
        $after = $this->settledSale();
        $before = $this->report('summary', ['from' => '2026-11-17', 'until' => '2026-11-17']);
        $next = $this->report('sales', ['from' => '2026-11-18', 'until' => '2026-11-18']);
        $this->assertSame(2, $before->totals['paid_sales']);
        $this->assertSame('360.0000', $before->totals['confirmed_income']);
        $this->assertSame('2026-11-17', $before->series[0]['business_date']);
        $this->assertSame(1, $next->total);
        $this->assertSame($after->sale->id, $next->items[0]['sale_id']);
        $this->assertStringStartsWith('2026-11-18T00:00:00-06:00', $next->items[0]['occurred_at']);
    }

    public function test_discarded_and_unchanged_corrections_never_create_additional_income(): void
    {
        $sale = $this->settledSale();
        $row = $this->reopen($sale);
        $row = $this->correction($row, 'edit', ['action' => 'quantity', 'item_id' => $sale->items[0]->id, 'quantity' => 2]);
        $this->correction($row, 'discard');
        $row = $this->reopen($sale);
        $this->correction($row, 'confirm', ['payments' => []]);
        $discarded = $this->report('corrections', ['state' => 'discarded']);
        $unchanged = $this->report('corrections', ['state' => 'unchanged']);
        $this->assertSame(1, $discarded->total);
        $this->assertNull($discarded->items[0]['after_total']);
        $this->assertNull($discarded->items[0]['difference']);
        $this->assertSame('180.0000', $unchanged->items[0]['before_total']);
        $this->assertSame('180.0000', $unchanged->items[0]['after_total']);
        $this->assertSame('0.0000', $unchanged->items[0]['difference']);
        $summary = $this->report('summary');
        $this->assertSame(1, $summary->totals['paid_sales']);
        $this->assertSame('180.0000', $summary->totals['confirmed_income']);
    }
}
