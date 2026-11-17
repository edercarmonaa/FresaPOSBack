<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Log;
use Modules\Core\Contracts\IdempotentOperations;
use Modules\Core\Contracts\OperationIntent;
use Modules\Core\Contracts\OperationResult;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Tests\Support\TestDatabaseGuard;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || $app->configurationIsCached()) {
    throw new RuntimeException('Solo testing sin cache.');
}
$sql = config('database.connections.sqlsrv_testing');
config(['database.default' => 'sqlsrv_testing', 'database.connections' => ['sqlsrv_testing' => $sql], 'cache.default' => 'array']);
$db = app('db')->connection();
TestDatabaseGuard::assertConnected($db);
$directory = realpath($argv[1]);
$root = realpath(dirname(__DIR__, 3).'/.tools');
if (! $directory || ! str_starts_with($directory, $root.DIRECTORY_SEPARATOR.'idempotency-deadlock-')) {
    throw new RuntimeException('Ruta inválida.');
}
$input = json_decode(file_get_contents($directory.'/input.json'), true, 512, JSON_THROW_ON_ERROR);
$worker = $argv[2];
if (! in_array($worker, ['first', 'second'], true) || preg_match('/\Aprobe_[a-f0-9]{12}_parents\z/', $input['table']) !== 1) {
    throw new RuntimeException('Fixture inválida.');
}
$handler = new TestHandler;
Log::extend('deadlock-memory', fn () => new Logger('deadlock', [$handler]));
config(['logging.default' => 'deadlock-memory', 'logging.channels.deadlock-memory' => ['driver' => 'deadlock-memory']]);
$db->statement($worker === 'first' ? 'SET DEADLOCK_PRIORITY HIGH' : 'SET DEADLOCK_PRIORITY LOW');
$attempts = 0;
$ids = $worker === 'first' ? $input['ids'] : array_reverse($input['ids']);
$intent = new OperationIntent(OperationIntent::context('deadlock-'.$worker), $input['keys'][$worker], 'probe.deadlock', 'POST', null, ['ids' => $ids]);
file_put_contents($directory.'/'.$worker.'-spid.json', json_encode(['spid' => (int) $db->selectOne('SELECT @@SPID AS id')->id]));
$result = app(IdempotentOperations::class)->run($intent, fn (bool $locking) => null, function () use (&$attempts, $db, $input, $ids, $directory, $worker): OperationResult {
    $attempts++;
    $db->table($input['table'])->where('id', $ids[0])->increment('amount');
    if ($attempts === 1) {
        file_put_contents($directory.'/'.$worker.'-holding', 'ready');
        $deadline = microtime(true) + 20;
        while (! is_file($directory.'/go')) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Timeout de deadlock.');
            }usleep(20000);
        }
    }
    $db->table($input['table'])->where('id', $ids[1])->increment('amount');

    return new OperationResult(200, ['updated' => 2]);
});
$codes = [];
foreach ($handler->getRecords() as $record) {
    if ($record->message === 'Retrying durable operation.') {
        $codes[] = $record->context['sql_code'];
    }
}
file_put_contents($directory.'/'.$worker.'-result.json', json_encode(['attempts' => $attempts, 'status' => $result->httpStatus, 'transient_sql_codes' => $codes], JSON_THROW_ON_ERROR));
