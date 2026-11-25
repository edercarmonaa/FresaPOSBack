<?php

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Event;
use Modules\Core\Contracts\OperationRejected;
use Modules\Reports\Contracts\OperationalReports;
use Modules\Sales\Contracts\SaleCorrections;
use Tests\Support\TestDatabaseGuard;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || $app->configurationIsCached()) {
    throw new RuntimeException('Isolated testing required');
}
$sql = config('database.connections.sqlsrv_testing');
config(['database.default' => 'sqlsrv_testing', 'database.connections' => ['sqlsrv_testing' => $sql], 'cache.default' => 'array']);
$db = app('db')->connection();
TestDatabaseGuard::assertConnected($db);
$directory = realpath($argv[1]);
$root = realpath(dirname(__DIR__, 3).'/.tools');
$mode = $argv[2];
if (! $directory || ! str_starts_with($directory, $root.DIRECTORY_SEPARATOR.'reports-race-') || ! in_array($mode, ['read', 'confirm'], true)) {
    throw new RuntimeException('Invalid worker');
}
$input = json_decode(file_get_contents($directory.'/input.json'), true, 512, JSON_THROW_ON_ERROR);
CarbonImmutable::setTestNow('2026-11-17T20:00:00Z');
file_put_contents($directory.'/'.$mode.'-attempt.json', json_encode(['spid' => (int) $db->selectOne('SELECT @@SPID AS id')->id]));
if ($mode === 'read') {
    $armed = true;
    Event::listen(QueryExecuted::class, function (QueryExecuted $event) use (&$armed, $directory): void {
        if ($armed && str_contains($event->sql, 'AS paid_sales') && str_contains($event->sql, '[sales_sales]')) {
            $armed = false;
            file_put_contents($directory.'/holding', 'ready');
            $until = microtime(true) + 20;
            while (! is_file($directory.'/release')) {
                if (microtime(true) > $until) {
                    throw new RuntimeException('Fixture timeout');
                }usleep(20000);
            }
        }
    });
    try {
        $result = ['status' => 200, 'data' => app(OperationalReports::class)->read($input['actor'], 'summary')->jsonSerialize()];
    } catch (OperationRejected $exception) {
        $result = ['status' => $exception->apiCode->status(), 'code' => $exception->apiCode->value];
    }
} else {
    $result = app(SaleCorrections::class)->write($input['actor'], $input['saleId'], 'confirm', ['payments' => $input['payments']], $input['key'], '"'.$input['revision'].'"');
}
file_put_contents($directory.'/'.$mode.'-result.json', json_encode($result, JSON_THROW_ON_ERROR));
