<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Modules\Core\Contracts\SecurityEvent;
use Modules\Users\Contracts\IdentityAccess;
use Tests\Support\TestDatabaseGuard;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || $app->configurationIsCached()) {
    throw new RuntimeException('Solo testing sin cache.');
}
$sql = config('database.connections.sqlsrv_testing');
config(['database.default' => 'sqlsrv_testing', 'database.connections' => ['sqlsrv_testing' => $sql], 'cache.default' => 'array', 'session.driver' => 'array', 'app.debug' => false]);
$db = app('db')->connection();
TestDatabaseGuard::assertConnected($db);
$directory = realpath($argv[1]);
$root = realpath(dirname(__DIR__, 3).'/.tools');
$worker = $argv[2];
if (! $directory || ! str_starts_with($directory, $root.DIRECTORY_SEPARATOR.'finance-race-') || ! in_array($worker, ['first', 'second', 'retry'], true)) {
    throw new RuntimeException('Worker o ruta de fixture inválidos.');
}
$input = json_decode(file_get_contents($directory.'/input.json'), true, 512, JSON_THROW_ON_ERROR);
$spid = (int) $db->selectOne('SELECT @@SPID AS id')->id;
$initialConnectionId = (string) $db->selectOne('SELECT CONVERT(varchar(36),connection_id) AS id FROM sys.dm_exec_connections WHERE session_id=@@SPID')->id;
file_put_contents($directory.'/'.$worker.'-attempt.json', json_encode(['spid' => $spid]));
$hold = function () use ($directory): void {
    file_put_contents($directory.'/holding', 'ready');
    $deadline = microtime(true) + 25;
    while (! is_file($directory.'/release')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Timeout de fixture');
        }usleep(20000);
    }
};
if ($worker === 'first' && $input['mode'] === 'transition') {
    $db->transaction(function () use ($db, $input, $hold): void {
        app(IdentityAccess::class)->lock();
        $db->table('cash_registers')->lock('with (updlock,holdlock)')->first();
        $row = $db->table('cash_sessions')->where('id', $input['sessionId'])->lock('with (updlock,holdlock)')->first();
        if (! $row || $row->status !== 'open') {
            throw new RuntimeException('Sesión fixture inválida');
        }$db->table('cash_sessions')->where('id', $input['sessionId'])->update(['status' => 'closed', 'closed_at' => now()->utc(), 'closed_by' => $input['actor']]);
        $hold();
    });
    file_put_contents($directory.'/first-result.json', json_encode(['status' => 200, 'fixture_transition' => true]));
    exit;
}
$armed = false;
$committing = false;
$connectionKilled = false;
if ($worker === 'first') {
    Event::listen(QueryExecuted::class, function (QueryExecuted $event) use (&$armed): void {
        if (str_starts_with(strtolower(ltrim($event->sql)), 'insert') && str_contains(strtolower($event->sql), 'idempotency_records')) {
            $armed = true;
        }
    });
    Event::listen(TransactionCommitting::class, function (TransactionCommitting $event) use (&$armed, &$committing, $hold): void {
        if ($armed && $event->connection->transactionLevel() === 1) {
            $armed = false;
            $committing = true;
            $hold();
        }
    });
    if ($input['mode'] === 'rollback') {
        Event::listen(SecurityEvent::class, function (SecurityEvent $event) use ($hold): void {
            if ($event->type === 'cash.movement.recorded') {
                $hold();
                throw new RuntimeException('Fixture audit rollback');
            }
        });
    }
    if ($input['mode'] === 'connection_after_commit') {
        Event::listen(TransactionCommitted::class, function (TransactionCommitted $event) use (&$committing, &$connectionKilled, $db, $spid, $sql): void {
            if (! $committing || $event->connection->transactionLevel() !== 0) {
                return;
            }$committing = false;
            config(['database.connections.finance_killer' => $sql]);
            $killer = app('db')->connection('finance_killer');
            TestDatabaseGuard::assertConnected($killer);
            $target = $killer->selectOne('SELECT session_id FROM sys.dm_exec_sessions WHERE session_id=? AND database_id=DB_ID() AND session_id<>@@SPID', [$spid]);
            if (! $target || (int) $target->session_id !== $spid) {
                throw new RuntimeException('No se puede validar el destino SQL de fixture');
            }
            $killer->unprepared('KILL '.(string) $spid);
            $connectionKilled = true;
            app('db')->purge('finance_killer');
            // Consulta real sobre la conexión terminada: el driver genera el error transitorio.
            $db->select('SELECT 1 AS connection_probe');
            throw new RuntimeException('El driver no detectó la terminación SQL de fixture');
        });
    }
}
$which = $worker === 'first' ? 'first' : 'second';
$payload = $input['payloads'][$which];
$key = $input['keys'][$which];
$request = Request::create('/api/v1/cash/movements', 'POST', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$input['token'], 'HTTP_IDEMPOTENCY_KEY' => $key], content: json_encode($payload, JSON_THROW_ON_ERROR));
$kernel = $app->make(HttpKernel::class);
$response = $kernel->handle($request);
$body = json_decode($response->getContent(), true);
$delivered = ! ($worker === 'first' && $input['mode'] === 'lost');
file_put_contents($directory.'/'.$worker.'-result.json', json_encode(['status' => $response->getStatusCode(), 'body' => $delivered ? $body : null, 'delivered' => $delivered, 'connection_killed' => $connectionKilled, 'final_spid' => (int) app('db')->connection()->selectOne('SELECT @@SPID AS id')->id,
    'initial_connection_id' => $initialConnectionId, 'final_connection_id' => (string) app('db')->connection()->selectOne('SELECT CONVERT(varchar(36),connection_id) AS id FROM sys.dm_exec_connections WHERE session_id=@@SPID')->id], JSON_THROW_ON_ERROR));
$kernel->terminate($request, $response);
