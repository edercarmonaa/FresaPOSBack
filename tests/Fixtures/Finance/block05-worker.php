<?php

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Event;
use Modules\Core\Contracts\OperationRejected;
use Modules\Reports\Application\ExportOperationalReport;
use Modules\Reports\Application\ReportPresentation;
use Modules\Sales\Contracts\SaleCheckout;
use Modules\Sales\Contracts\SaleCorrections;
use Modules\Tickets\Contracts\SaleReceipts;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\TestDatabaseGuard;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || $app->configurationIsCached()) {
    throw new RuntimeException('Isolated uncached testing required');
}
$sql = config('database.connections.sqlsrv_testing');
config(['database.default' => 'sqlsrv_testing', 'database.connections' => ['sqlsrv_testing' => $sql], 'cache.default' => 'array']);
$db = app('db')->connection();
TestDatabaseGuard::assertConnected($db);
$directory = realpath($argv[1]);
$root = realpath(dirname(__DIR__, 3).'/.tools');
$role = $argv[2];
if (! $directory || ! str_starts_with($directory, $root.DIRECTORY_SEPARATOR.'block05-race-') || ! in_array($role, ['first', 'second'], true)) {
    throw new RuntimeException('Invalid isolated worker');
}
$input = json_decode(file_get_contents($directory.'/input.json'), true, 512, JSON_THROW_ON_ERROR);
CarbonImmutable::setTestNow('2026-11-17T20:00:00Z');
file_put_contents($directory.'/'.$role.'-attempt.json', json_encode(['spid' => (int) $db->selectOne('SELECT @@SPID AS id')->id]));
if ($role === 'first') {
    $armed = true;
    Event::listen(QueryExecuted::class, function (QueryExecuted $query) use (&$armed, $directory, $input): void {
        $trigger = $input['mode'] === 'export_checkout'
            ? str_contains($query->sql, 'AS paid_sales') && str_contains($query->sql, '[sales_sales]')
            : str_starts_with($query->sql, 'SELECT id FROM user_security_locks');
        if ($armed && $trigger) {
            $armed = false;
            file_put_contents($directory.'/holding', 'ready');
            $deadline = microtime(true) + 20;
            while (! is_file($directory.'/release')) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Fixture barrier timeout');
                }
                usleep(20000);
            }
        }
    });
}
try {
    if ($input['mode'] === 'export_checkout' && $role === 'first') {
        $file = app(ExportOperationalReport::class)->generate($input['actor'], 'summary', 'xlsx');
        $path = $directory.'/concurrent-summary.xlsx';
        file_put_contents($path, $file->contents);
        $book = IOFactory::load($path);
        $sheet = $book->getActiveSheet();
        $metrics = [];
        $required = ['confirmed_income', 'cash_applied', 'paid_sales', 'units_sold'];
        foreach ($sheet->getRowIterator() as $row) {
            foreach ($row->getCellIterator() as $cell) {
                foreach ($required as $metric) {
                    if (! isset($metrics[$metric]) && $cell->getValue() === ReportPresentation::METRICS[$metric]) {
                        $value = $sheet->getCell([Coordinate::columnIndexFromString($cell->getColumn()) + 1, $cell->getRow()])->getValue();
                        if (is_numeric($value)) {
                            $metrics[$metric] = in_array($metric, ['paid_sales', 'units_sold'], true) ? (int) $value : number_format((float) $value, 4, '.', '');
                        }
                    }
                }
            }
        }
        $book->disconnectWorksheets();
        $result = ['status' => 200, 'metrics' => $metrics, 'binary_xlsx' => str_starts_with($file->contents, 'PK')];
    } elseif ($input['mode'] === 'export_checkout') {
        $sale = app(SaleCheckout::class)->confirm($input['actor'], $input['saleId'], $input['payments'], $input['key'], $input['revision']);
        $result = ['status' => 200, 'total' => $sale->sale->total];
    } elseif ($role === 'first') {
        $row = app(SaleCorrections::class)->write($input['actor'], $input['saleId'], 'confirm', ['payments' => $input['payments']], $input['key'], $input['revision']);
        $result = ['status' => 200, 'correction_status' => $row['status']];
    } else {
        $receipt = app(SaleReceipts::class)->reprint($input['actor'], $input['saleId'], $input['receiptRevision'], $input['reprintKey']);
        $result = ['status' => 200, 'total' => $receipt->total];
    }
} catch (OperationRejected $exception) {
    $result = ['status' => $exception->apiCode->status(), 'code' => $exception->apiCode->value];
}
file_put_contents($directory.'/'.$role.'-result.json', json_encode($result, JSON_THROW_ON_ERROR));
