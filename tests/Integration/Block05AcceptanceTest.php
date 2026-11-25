<?php

namespace Tests\Integration;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Cash\Contracts\CashMovements;
use Modules\Catalog\Contracts\CatalogAdministration;
use Modules\Reports\Application\ReportPresentation;
use Modules\Reports\Contracts\OperationalReports;
use Modules\Reports\Domain\ReportDefinition;
use Modules\Sales\Contracts\DiscountProposal;
use Modules\Sales\Contracts\SaleCheckout;
use Modules\Sales\Contracts\SalesOperations;
use Modules\Tickets\Contracts\SaleReceipts;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinancialIntegrity;
use Tests\Support\ReceiptsSqlServerTestCase;

class Block05AcceptanceTest extends ReceiptsSqlServerTestCase
{
    private array $cookies = [];

    private function web(string $method, string $path, array $data = []): TestResponse
    {
        Auth::forgetGuards();
        Auth::shouldUse('web');
        $response = $this->call($method, $path, $data, $this->cookies, [], ['HTTP_ACCEPT' => 'text/html']);
        foreach ($response->headers->getCookies() as $cookie) {
            $this->cookies[$cookie->getName()] = $cookie->getValue();
        }

        return $response;
    }

    public static function calendarBoundaries(): array
    {
        return [['2026-12-01', '2026-11-30'], ['2027-01-01', '2026-12-31']];
    }

    #[DataProvider('calendarBoundaries')]
    public function test_month_and_year_changes_keep_sql_report_bounds_and_persisted_folios_aligned(string $next, string $previous): void
    {
        CarbonImmutable::setTestNow($next.'T05:59:59Z');
        $before = $this->settledSale();
        CarbonImmutable::setTestNow($next.'T06:00:00Z');
        $after = $this->settledSale(true);
        $this->assertSame(str_replace('-', '', $previous).'0001', $before->sale->folio);
        $this->assertSame(str_replace('-', '', $next).'0001', $after->sale->folio);
        foreach ([[$previous, $before], [$next, $after]] as [$date, $sale]) {
            $report = app(OperationalReports::class)->read($this->actor, 'sales', ['from' => $date, 'until' => $date]);
            $this->assertSame(1, $report->total);
            $this->assertSame($sale->sale->folio, $report->items[0]['folio']);
            $this->assertSame('180.0000', $report->totals['confirmed_income']);
            $this->assertSame($sale->sale->folio, $this->receipt($sale->sale->id)->folio);
        }
    }

    public function test_composite_financial_journey_agrees_across_sales_cash_receipts_web_api_pdf_and_xlsx(): void
    {
        $directory = base_path('.tools/block05-artifacts');
        if (! is_dir($directory)) {
            mkdir($directory);
        }
        $checkout = app(SaleCheckout::class);
        $sales = app(SalesOperations::class);
        $receipts = app(SaleReceipts::class);
        $reports = app(OperationalReports::class);

        $counter = $this->discount($this->sale(), new DiscountProposal('percent', 25));
        $counter = $checkout->confirm($this->actor, $counter->sale->id, [$this->payment('cash', '135', '200')], (string) Str::uuid(), $this->revision($counter));
        $receipt = $this->receipt($counter->sale->id);
        $this->assertSame('65.0000', $receipt->payments[0]['change_cash']);
        $reprintKey = (string) Str::uuid();
        $beforeReprint = $this->financialState();
        $reprint = $receipts->reprint($this->actor, $counter->sale->id, $receipt->revision, $reprintKey);
        $this->assertEquals($reprint, $receipts->reprint($this->actor, $counter->sale->id, $receipt->revision, $reprintKey));
        $this->assertSame($beforeReprint, $this->financialState());
        $this->assertSame(1, $this->database->table('audit_security_events')->where('event_type', 'tickets.reprint.requested')->count());

        $table = $this->sale(true);
        $table = $checkout->confirm($this->actor, $table->sale->id, [$this->payment('cash', '80', '100'), $this->payment('card', '100', null)], (string) Str::uuid(), $this->revision($table));
        $originalPayments = $this->database->table('sales_payments')->where('sale_id', $table->sale->id)->orderBy('id')->get()->all();
        $correction = $this->reopen($table, 'Aceptación integral Bloque 05');
        $correction = $this->correction($correction, 'edit', ['action' => 'quantity', 'item_id' => $table->items[0]->id, 'quantity' => 2]);
        $confirmationKey = (string) Str::uuid();
        $confirmed = $this->correction($correction, 'confirm', ['payments' => [$this->payment('card', '180', null)]], $confirmationKey);
        $this->assertSame($confirmed, $this->correction($correction, 'confirm', ['payments' => [$this->payment('card', '180', null)]], $confirmationKey));
        $current = $sales->sale($this->actor, $table->sale->id);
        $this->assertSame($table->sale->id, $current->sale->id);
        $this->assertSame($table->sale->folio, $current->sale->folio);
        $this->assertSame($table->sale->closedAt, $current->sale->closedAt);
        $this->assertEquals($originalPayments, $this->database->table('sales_payments')->whereIn('id', array_column($originalPayments, 'id'))->orderBy('id')->get()->all());

        $zero = $this->discount($this->sale(), new DiscountProposal('percent', 100));
        $zero = $checkout->confirm($this->actor, $zero->sale->id, [], (string) Str::uuid(), $this->revision($zero));
        $this->assertSame('0.0000', $this->receipt($zero->sale->id)->total);
        $this->assertSame([], $this->receipt($zero->sale->id)->payments);
        $this->assertSame(0, $this->database->table('sales_payments')->where('sale_id', $zero->sale->id)->count());

        $cancelled = $this->sale();
        $cancelled = $this->control($cancelled, 'remove', 'QA_REMOVE');
        $this->control($cancelled, 'cancel', 'QA_CANCEL');
        $this->assertNotNull($this->database->table('sales_items')->where('sale_id', $cancelled->sale->id)->value('removed_at'));
        app(CatalogAdministration::class)->updateProduct($this->actor, $this->productId, ['name' => 'CURRENT CATALOG MUST NOT REWRITE HISTORY', 'category_id' => $counter->items[0]->categoryId, 'price' => '999']);
        app(CashMovements::class)->record($this->actor, $this->sessionId, 'cash_in', '50', 'Entrada exclusiva aceptación', (string) Str::uuid());
        app(CashMovements::class)->record($this->actor, $this->sessionId, 'cash_out', '10', 'Salida exclusiva aceptación', (string) Str::uuid());
        $this->assertSame('355.0000', $this->funds()['expected_cash']);
        $this->assertNull($reports->read($this->actor, 'cash-sessions')->items[0]['expected_cash']);
        $this->closeCount('355', observation: 'Conteo físico sintético de aceptación');

        $summary = $reports->read($this->actor, 'summary');
        foreach (['paid_sales' => 3, 'units_sold' => 4, 'confirmed_income' => '495.0000', 'subtotal_total' => '720.0000', 'discounts_total' => '225.0000', 'cash_applied' => '215.0000', 'card_applied' => '280.0000', 'closed_expected_cash' => '355.0000', 'closed_counted_cash' => '355.0000', 'closing_differences' => '0.0000'] as $metric => $expected) {
            $this->assertSame($expected, $summary->totals[$metric], $metric);
        }
        $this->assertSame('0.0000', $reports->read($this->actor, 'payments')->totals['reconciliation_difference']);
        $this->assertSame(3, (int) $this->database->table('sales_daily_sequences')->value('last_value'));
        $this->assertSame(4, $this->database->table('sales_payments')->count());
        $this->assertSame(2, $this->database->table('cash_movements')->count());
        $this->assertSame(1, $this->database->table('audit_security_events')->where('event_type', 'sales.correction.confirmed')->count());
        $this->assertTrue(FinancialIntegrity::inspect($this->database)['passed']);

        $evidence = ['database' => 'fresapos_test', 'fixture_only' => true, 'scenario' => 'counter discount cash/change + table mixed/additional correction + zero + removed/cancelled + manual cash + blind close', 'receipts' => [], 'reports' => []];
        foreach ([$counter, $current, $zero] as $paid) {
            $document = $this->receipt($paid->sale->id);
            $this->assertSame($paid->sale->folio, $document->folio);
            $this->assertSame($paid->sale->total, $document->total);
            $evidence['receipts'][] = $document->jsonSerialize();
        }
        Auth::forgetGuards();
        $token = $this->postJson('/api/v1/auth/login', ['username' => 'finance.fixture', 'password' => 'FinanceChanged!123'])->assertOk()->json('data.token');
        $this->web('POST', '/login', ['username' => 'finance.fixture', 'password' => 'FinanceChanged!123'])->assertRedirect();
        $financialBeforeExports = $this->financialState();
        foreach (array_keys(ReportDefinition::NAMES) as $report) {
            $data = $reports->read($this->actor, $report);
            $this->assertStringNotContainsString('CURRENT CATALOG MUST NOT REWRITE HISTORY', json_encode($data, JSON_THROW_ON_ERROR));
            Auth::forgetGuards();
            $response = $this->getJson('/api/v1/reports/'.$report, ['Authorization' => 'Bearer '.$token])->assertOk();
            $actual = $response->json('data');
            $expected = $data->jsonSerialize();
            unset($actual['generated_at'], $expected['generated_at']);
            $this->assertSame($expected, $actual);
            $web = $this->web('GET', '/reports/'.$report)->assertOk();
            $display = [];
            foreach ($data->items as $item) {
                $displayRow = [];
                foreach ($data->columns as $key => [$label, $type]) {
                    $value = ReportPresentation::value($item[$key] ?? null, $type, $data->timezone);
                    $web->assertSee($value);
                    $displayRow[] = $value;
                }
                $display[] = $displayRow;
            }
            foreach (['pdf', 'xlsx'] as $format) {
                Auth::forgetGuards();
                $download = $this->postJson('/api/v1/reports/'.$report.'/exports/'.$format, [], ['Authorization' => 'Bearer '.$token])->assertOk();
                $path = $directory.'/'.$report.'.'.$format;
                file_put_contents($path, $download->getContent());
                $this->assertStringStartsWith($format === 'pdf' ? '%PDF-' : 'PK', $download->getContent());
                if ($format === 'xlsx') {
                    $book = IOFactory::load($path);
                    $sheet = $book->getActiveSheet();
                    $header = (int) preg_replace('/\D/', '', explode(':', $sheet->getAutoFilter()->getRange())[0]);
                    $this->assertSame($data->title, $sheet->getCell('A1')->getValue());
                    $this->assertSame($data->timezone, $sheet->getCell('B4')->getValue());
                    $this->assertSame(ReportPresentation::filters($data->filters), $sheet->getCell('B5')->getValue());
                    $metricIndex = 0;
                    foreach ($data->totals as $metricValue) {
                        $metricCell = $sheet->getCell([2 + ($metricIndex % 3) * 2, 7 + intdiv($metricIndex, 3)]);
                        if ($metricValue === null) {
                            $this->assertNull($metricCell->getValue());
                        } elseif (is_int($metricValue)) {
                            $this->assertSame($metricValue, (int) $metricCell->getValue());
                        } else {
                            $this->assertSame(number_format((float) $metricValue, 4, '.', ''), number_format((float) $metricCell->getValue(), 4, '.', ''));
                        }
                        $metricIndex++;
                    }
                    $this->assertSame(count($data->items), (int) preg_replace('/\D/', '', explode(':', $sheet->getAutoFilter()->getRange())[1]) - $header);
                    foreach ($data->items as $index => $item) {
                        $column = 1;
                        foreach ($data->columns as $key => [$label, $type]) {
                            $cell = $sheet->getCell(Coordinate::stringFromColumnIndex($column++).($header + $index + 1));
                            $value = $item[$key] ?? null;
                            $this->assertNotSame(DataType::TYPE_FORMULA, $cell->getDataType());
                            if ($value === null) {
                                $this->assertNull($cell->getValue());
                            } elseif (in_array($type, ['money', 'decimal'], true)) {
                                $this->assertSame(number_format((float) $value, 4, '.', ''), number_format((float) $cell->getValue(), 4, '.', ''));
                            } elseif ($type === 'integer') {
                                $this->assertSame((int) $value, (int) $cell->getValue());
                            } else {
                                $this->assertSame(ReportPresentation::value($value, $type, $data->timezone), $cell->getFormattedValue());
                            }
                        }
                    }
                    $book->disconnectWorksheets();
                }
            }
            $evidence['reports'][$report] = ['data' => $data->jsonSerialize(), 'display_rows' => $display];
        }
        $this->assertSame($financialBeforeExports, $this->financialState());
        $this->assertSame(18, $this->database->table('audit_security_events')->where('event_type', 'reports.export.generated')->count());
        $evidence['financial_integrity'] = FinancialIntegrity::inspect($this->database);
        $evidence['summary_totals'] = $summary->totals;
        file_put_contents($directory.'/manifest.json', json_encode($evidence, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
}
