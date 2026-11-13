<?php

// Worker exclusivo de pruebas, invocado por Symfony Process; nunca registrado como comando/ruta.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Modules\Cash\Contracts\ActiveCashSession;
use Modules\Sales\Contracts\OpenSale;
use Modules\Sales\Contracts\SalesOperations;
use Modules\Sales\Domain\SalesConflict;
use Modules\Sales\Domain\SalesText;
use Modules\Sales\Domain\SaleType;
use Tests\Fixtures\Sales\TestCashSession;
use Tests\Support\TestDatabaseGuard;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || $app->configurationIsCached()) {
    throw new RuntimeException('Se requiere testing sin configuración cacheada.');
}
$sql = config('database.connections.sqlsrv_testing');
config(['database.default' => 'sqlsrv_testing', 'database.connections' => ['sqlsrv_testing' => $sql], 'cache.default' => 'array']);
$db = app('db')->connection('sqlsrv_testing');
TestDatabaseGuard::assertConnected($db);
$directory = realpath($argv[1]);
$root = realpath(dirname(__DIR__, 3).'/.tools');
if (! $directory || ! str_starts_with($directory, $root.DIRECTORY_SEPARATOR.'sales-race-')) {
    throw new RuntimeException('Directorio de concurrencia ajeno al workspace.');
}
$input = json_decode(file_get_contents($directory.'/input.json'), true, 512, JSON_THROW_ON_ERROR);
foreach (['actor', 'table', 'cash'] as $field) {
    SalesText::id($input[$field]);
}
$worker = $argv[2];
if (! in_array($worker, ['first', 'second'], true)) {
    throw new RuntimeException('Worker no permitido.');
}
$app->instance(ActiveCashSession::class, new TestCashSession($input['cash']));
$spid = (int) $db->selectOne('SELECT @@SPID AS id')->id;
file_put_contents($directory.'/'.$worker.'-attempt.json', json_encode(['spid' => $spid]));
$status = [];
try {
    $db->beginTransaction();
    if ($input['mode'] === 'application') {
        $saleId = app(SalesOperations::class)->open($input['actor'], new OpenSale(SaleType::Table, $input['table']))->sale->id;
    } else {
        $saleId = strtolower((string) Str::ulid());
        $db->table('sales_sales')->insert(['id' => $saleId, 'type' => 'table', 'status' => 'open', 'table_id' => $input['table'],
            'created_by' => $input['actor'], 'cash_session_id' => $input['cash'], 'created_at' => now()->utc(), 'updated_at' => now()->utc()]);
    }
    if ($worker === 'first') {
        file_put_contents($directory.'/holding', 'ready');
        $deadline = microtime(true) + 20;
        while (! is_file($directory.'/release')) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Timeout esperando liberación del primer commit.');
            }
            usleep(20000);
        }
    }
    $db->commit();
    $status = ['status' => 'created', 'id' => $saleId];
} catch (Throwable $exception) {
    while ($db->transactionLevel() > 0) {
        $db->rollBack();
    }
    $code = $exception instanceof QueryException ? (int) ($exception->errorInfo[1] ?? 0) : null;
    $status = ['status' => $exception instanceof SalesConflict || in_array($code, [2601, 2627], true) ? 'conflict' : 'error',
        'exception' => $exception::class, 'sql_code' => $code];
}
file_put_contents($directory.'/'.$worker.'-result.json', json_encode($status, JSON_THROW_ON_ERROR));
echo json_encode($status, JSON_THROW_ON_ERROR).PHP_EOL;
exit($status['status'] === 'error' ? 1 : 0);
