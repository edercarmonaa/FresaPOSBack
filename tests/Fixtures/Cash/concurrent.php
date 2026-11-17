<?php

// Solo pruebas: dos kernels y conexiones SQL independientes; conexión operativa excluida.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Modules\Cash\Contracts\ActiveCashSession;
use Modules\Cash\Contracts\CashConflict;
use Modules\Cash\Contracts\CashOperations;
use Modules\Cash\Contracts\CashSessionUnavailable;
use Modules\Sales\Contracts\OpenSale;
use Modules\Sales\Contracts\SalesOperations;
use Modules\Sales\Domain\SalesConflict;
use Modules\Sales\Domain\SaleType;
use Modules\Users\Contracts\IdentityAccess;
use Tests\Support\TestDatabaseGuard;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || $app->configurationIsCached()) {
    throw new RuntimeException('Testing sin configuración cacheada requerido.');
}
$sql = config('database.connections.sqlsrv_testing');
config(['database.default' => 'sqlsrv_testing', 'database.connections' => ['sqlsrv_testing' => $sql], 'cache.default' => 'array']);
$db = app('db')->connection('sqlsrv_testing');
TestDatabaseGuard::assertConnected($db);
$directory = realpath($argv[1]);
$root = realpath(dirname(__DIR__, 3).'/.tools');
if (! $directory || ! str_starts_with($directory, $root.DIRECTORY_SEPARATOR.'cash-race-')) {
    throw new RuntimeException('Directorio ajeno al workspace.');
}
$input = json_decode(file_get_contents($directory.'/input.json'), true, 512, JSON_THROW_ON_ERROR);
$worker = $argv[2];
if (! in_array($worker, ['first', 'second'], true) || ! in_array($input['mode'], ['application', 'unique_index', 'replay', 'current', 'transition', 'table'], true)) {
    throw new RuntimeException('Modo no permitido.');
}
foreach (['actor', 'other', 'register'] as $field) {
    if (! Str::isUlid($input[$field])) {
        throw new RuntimeException('Identificador inválido.');
    }
}
file_put_contents($directory.'/'.$worker.'-attempt.json', json_encode(['spid' => (int) $db->selectOne('SELECT @@SPID AS id')->id]));
try {
    $db->beginTransaction();
    $mode = $input['mode'];
    $actor = $worker === 'second' && $mode === 'application' ? $input['other'] : $input['actor'];
    $result = ['status' => 'created'];
    if ($mode === 'transition') {
        if ($worker === 'first') {
            app(IdentityAccess::class)->lock();
            $id = app(ActiveCashSession::class)->requireActive($actor);
            // Transición exclusivamente de fixture; no hay cierre productivo implementado.
            $db->table('cash_sessions')->where('id', $id)->update(['status' => 'closed', 'closed_at' => now()->utc()->addSecond(), 'closed_by' => $actor]);
            $result['status'] = 'transitioned';
        } else {
            $id = app(SalesOperations::class)->open($actor, new OpenSale(SaleType::Counter))->sale->id;
        }
    } elseif ($mode === 'table') {
        $id = app(SalesOperations::class)->open($actor, new OpenSale(SaleType::Table, $input['table']))->sale->id;
    } elseif ($mode === 'current' && $worker === 'second') {
        $id = app(CashOperations::class)->current($actor)?->id;
        $result['status'] = 'queried';
    } elseif ($mode === 'unique_index') {
        $id = strtolower((string) Str::ulid());
        $db->table('cash_sessions')->insert(['id' => $id, 'register_id' => $input['register'], 'opened_by' => $actor, 'status' => 'open',
            'opening_fund' => '100.0000', 'opened_at' => now()->utc(), 'opening_key_hash' => hash('sha256', $worker), 'opening_fingerprint' => hash('sha256', 'payload')]);
    } else {
        $key = $mode === 'replay' ? 'cash-race-replay-123456' : 'cash-race-'.$worker.'-123456';
        $id = app(CashOperations::class)->open($actor, $input['register'], '100', $key)->id;
    }
    $result['id'] = $id;
    if ($worker === 'first') {
        file_put_contents($directory.'/holding', 'ready');
        $deadline = microtime(true) + 20;
        while (! is_file($directory.'/release')) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Timeout esperando commit.');
            }
            usleep(20000);
        }
    }
    $db->commit();
} catch (Throwable $exception) {
    while ($db->transactionLevel() > 0) {
        $db->rollBack();
    }
    $code = $exception instanceof QueryException ? (int) ($exception->errorInfo[1] ?? 0) : null;
    $result = ['status' => $exception instanceof CashSessionUnavailable ? 'unavailable'
        : ($exception instanceof CashConflict || $exception instanceof SalesConflict || in_array($code, [2601, 2627], true) ? 'conflict' : 'error'),
        'exception' => $exception::class, 'sql_code' => $code];
}
file_put_contents($directory.'/'.$worker.'-result.json', json_encode($result, JSON_THROW_ON_ERROR));
echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;
exit($result['status'] === 'error' ? 1 : 0);
