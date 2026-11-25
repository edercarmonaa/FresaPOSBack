<?php

namespace Tests\Integration;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Modules\Core\Contracts\SecurityEvent;
use Modules\Reports\Application\ExportOperationalReport;
use Modules\Reports\Contracts\OperationalReports;
use Modules\Reports\Contracts\ReportData;
use Modules\Reports\Domain\ReportDefinition;
use Modules\Reports\Infrastructure\ReadReportSnapshot;
use Modules\Reports\Infrastructure\ReportRenderer;
use Modules\Users\Contracts\IdentityAccess;
use Modules\Users\Contracts\UserAdministration;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ReceiptsSqlServerTestCase;

class ReportExportsTest extends ReceiptsSqlServerTestCase
{
    public static function formats(): array
    {
        return [['pdf'], ['xlsx']];
    }

    #[DataProvider('formats')]
    public function test_real_export_preserves_finances_and_records_one_safe_generation_event(string $format): void
    {
        $this->settledSale();
        $before = $this->financialState();
        $file = app(ExportOperationalReport::class)->generate($this->actor, 'sales', $format);
        $this->assertStringStartsWith($format === 'pdf' ? '%PDF-' : 'PK', $file->contents);
        $this->assertGreaterThan(1000, strlen($file->contents));
        $events = $this->database->table('audit_security_events')->where('event_type', 'reports.export.generated')->get();
        $this->assertCount(1, $events);
        $audit = json_decode($events[0]->change_data, true, 512, JSON_THROW_ON_ERROR)['report_export'];
        $this->assertSame('sales', $audit['report']);
        $this->assertSame($format, $audit['format']);
        $this->assertSame(1, $audit['rows']);
        $this->assertFalse($audit['deliveryConfirmed']);
        $this->assertArrayNotHasKey('items', $audit);
        $this->assertArrayNotHasKey('totals', $audit);
        $this->assertSame($before, $this->financialState());
        file_put_contents(base_path('.tools/report-sample-sales.'.$format), $file->contents);
    }

    public function test_saved_xlsx_has_typed_dates_exact_display_and_literal_formula_like_names(): void
    {
        $base = app(OperationalReports::class)->read($this->actor, 'products');
        $names = ['=1+1', '+SUM(A1:A2)', '-2+3', '@SUM(A1:A2)', 'Producto extenso áéíóú ñ <script>'];
        $items = array_map(fn ($name) => ['product_name' => $name, 'product_id' => $this->productId, 'category_name' => 'Categoría histórica', 'quantity' => 2, 'amount' => '123.4567', 'share' => '20.0000'], $names);
        $data = new ReportData('products', $base->title, $base->generatedAt, $base->timezone, $base->filters, ReportDefinition::columns('products'), $items, ['gross_items_amount' => '617.2835'], 5, 1, 5, [], $base->isolation);
        $file = app(ReportRenderer::class)->render($data, 'xlsx');
        $path = base_path('.tools/report-formula-check.xlsx');
        file_put_contents($path, $file->contents);
        $book = IOFactory::load($path);
        $sheet = $book->getActiveSheet();
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('B2')->getDataType());
        $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell('B3')->getDataType());
        $found = [];
        foreach ($sheet->getRowIterator() as $row) {
            foreach ($row->getCellIterator() as $cell) {
                $this->assertNotSame(DataType::TYPE_FORMULA, $cell->getDataType());
                if (in_array($cell->getValue(), $names, true)) {
                    $found[] = $cell->getValue();
                    $this->assertSame(DataType::TYPE_STRING, $cell->getDataType());
                }if ((string) $cell->getValue() === '123.4567') {
                    $this->assertSame(DataType::TYPE_NUMERIC, $cell->getDataType());
                    $this->assertStringContainsString('123.4567', $cell->getFormattedValue());
                }
            }
        }
        $this->assertSame($names, $found);
        $this->assertCount(1, $book->getAllSheets());
        $this->assertNotSame('', $sheet->getFreezePane());
        $this->assertNotSame('', $sheet->getAutoFilter()->getRange());
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $this->assertFalse($zip->locateName('xl/vbaProject.bin'));
        $zip->close();
        $book->disconnectWorksheets();
    }

    public function test_audit_failure_does_not_deliver_export_or_mutate_finances(): void
    {
        $this->settledSale();
        $before = $this->financialState();
        Event::listen(SecurityEvent::class, function (SecurityEvent $event): void {
            if ($event->type === 'reports.export.generated') {
                throw new \RuntimeException('QA audit unavailable');
            }
        });
        try {
            app(ExportOperationalReport::class)->generate($this->actor, 'sales', 'xlsx');
            $this->fail('Debe abortar la entrega');
        } catch (\RuntimeException $e) {
            $this->assertSame('QA audit unavailable', $e->getMessage());
        }
        $this->assertSame(0, $this->database->table('audit_security_events')->where('event_type', 'reports.export.generated')->count());
        $this->assertSame($before, $this->financialState());
    }

    public static function unsafeExports(): array
    {
        return [['pdf', 301, '1.0000'], ['xlsx', 1, '100000000000.0001']];
    }

    #[DataProvider('unsafeExports')]
    public function test_pdf_size_and_xlsx_precision_limits_are_enforced(string $format, int $rows, string $amount): void
    {
        $base = app(OperationalReports::class)->read($this->actor, 'products');
        $data = new ReportData('products', $base->title, $base->generatedAt, $base->timezone, $base->filters, ReportDefinition::columns('products'), [['product_name' => 'QA', 'amount' => $amount]], [], $rows, 1, $rows, [], $base->isolation);
        $this->expectException(ValidationException::class);
        app(ReportRenderer::class)->render($data, $format);
    }

    public function test_failed_snapshot_restores_connection_options_and_rolls_back(): void
    {
        $options = $this->database->select('DBCC USEROPTIONS');
        try {
            app(ReadReportSnapshot::class)->run(function (string $level): never {
                $options = $this->database->select('DBCC USEROPTIONS');
                $isolation = array_values(array_filter($options, fn ($option) => array_values((array) $option)[0] === 'isolation level'));
                $row = (array) $isolation[0];
                $this->assertSame(strtolower($level), end($row));
                throw new \RuntimeException('QA snapshot');
            });
            $this->fail();
        } catch (\RuntimeException $e) {
            $this->assertSame('QA snapshot', $e->getMessage());
        }
        $this->assertSame(0, $this->database->transactionLevel());
        $this->assertEquals($options, $this->database->select('DBCC USEROPTIONS'));
    }

    public function test_owner_permission_revoked_during_render_blocks_final_delivery(): void
    {
        $users = app(UserAdministration::class);
        $users->createRole($this->actor, 'report_partial', 'Reporte QA');
        $permissions = ['reports.view', 'reports.export', 'cash.administrative.execute', 'sales.view'];
        $users->setRolePermissions($this->actor, 'report_partial', $permissions);
        $user = $users->create($this->actor, ['name' => 'Reporte QA', 'username' => 'reports.partial', 'password' => 'ReportsInitial!123']);
        $users->assignRole($this->actor, $user->id, 'report_partial');
        app(IdentityAccess::class)->changePassword($user->id, 'ReportsInitial!123', 'ReportsChanged!123');
        $calls = 0;
        $access = function () use (&$calls, $users): void {
            if (++$calls === 2) {
                $users->setRolePermissions($this->actor, 'report_partial', ['reports.view', 'reports.export', 'cash.administrative.execute']);
            }
        };
        try {
            app(ExportOperationalReport::class)->generate($user->id, 'sales', 'xlsx', [], $access);
            $this->fail('La revocación debe impedir entrega');
        } catch (AuthorizationException) {
            $this->assertSame(2, $calls);
        }
        $this->assertSame(0, $this->database->table('audit_security_events')->where('event_type', 'reports.export.generated')->count());
    }

    public function test_export_ignores_visible_page_and_contains_every_filtered_sale(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->settledSale();
        }
        $filters = ['page' => 2, 'per_page' => 10, 'direction' => 'asc'];
        $page = app(OperationalReports::class)->read($this->actor, 'sales', $filters);
        $this->assertCount(2, $page->items);
        $this->assertSame(12, $page->total);
        $this->assertSame('2160.0000', $page->totals['confirmed_income']);
        $xlsx = app(ExportOperationalReport::class)->generate($this->actor, 'sales', 'xlsx', $filters);
        $path = base_path('.tools/report-all-filtered.xlsx');
        file_put_contents($path, $xlsx->contents);
        $book = IOFactory::load($path);
        $folios = [];
        foreach ($book->getActiveSheet()->getColumnIterator('A', 'A') as $column) {
            foreach ($column->getCellIterator() as $cell) {
                if (preg_match('/\A20261117[0-9]{4}\z/', (string) $cell->getValue())) {
                    $folios[] = $cell->getValue();
                    $this->assertSame(DataType::TYPE_STRING, $cell->getDataType());
                }
            }
        }
        $this->assertCount(12, $folios);
        $this->assertCount(12, array_unique($folios));
        $book->disconnectWorksheets();
        $pdf = app(ExportOperationalReport::class)->generate($this->actor, 'sales', 'pdf', $filters);
        file_put_contents(base_path('.tools/report-all-filtered.pdf'), $pdf->contents);
        $this->assertSame(2, $this->database->table('audit_security_events')->where('event_type', 'reports.export.generated')->count());
    }
}
