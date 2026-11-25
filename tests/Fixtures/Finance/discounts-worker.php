<?php

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Modules\Core\Contracts\SecurityEvent;
use Modules\Sales\Contracts\SalesOperations;
use Modules\Users\Contracts\IdentityAccess;
use Modules\Users\Contracts\UserAdministration;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Tests\Support\TestDatabaseGuard;

$traceContext = [];
$traceRun = basename($argv[1] ?? '');
$traceRole = $argv[2] ?? '';
if (preg_match('/\A(?:checkout|discounts)-race-[a-f0-9]{12}\z/', $traceRun) && in_array($traceRole, ['first', 'second', 'retry'], true)) {
    $traceContext = ['run_id' => $traceRun, 'worker' => $traceRole];
}
$traceLastIo = 0.0;
$traceStarted = hrtime(true);
$traceFile = dirname(__DIR__, 3).'/.tools/stability-worker-trace-'.getmypid().'-'.$traceStarted.'.jsonl';
$trace = function (string $phase, array $details = []) use ($traceStarted, $traceFile, &$traceContext, &$traceLastIo): void {
    $traceIoStarted = hrtime(true);
    $line = json_encode(['phase' => $phase, 'previous_trace_io_ms' => $traceLastIo, 'pid' => getmypid(), 'monotonic_ns' => hrtime(true), 'elapsed_ms' => (hrtime(true) - $traceStarted) / 1000000] + $traceContext + $details, JSON_THROW_ON_ERROR).PHP_EOL;
    file_put_contents($traceFile, $line, FILE_APPEND);
    fwrite(STDERR, $line);
    $traceLastIo = (hrtime(true) - $traceIoStarted) / 1000000;
};
$trace('autoload.begin');
register_shutdown_function(function () use ($trace): void {
    $error = error_get_last();
    $trace('process.shutdown', ['fatal_error' => $error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)]);
});
require dirname(__DIR__, 3).'/vendor/autoload.php';
$trace('bootstrap.begin');
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
$trace('bootstrap.ready');
if (! $app->environment('testing') || $app->configurationIsCached()) {
    throw new RuntimeException('Uncached testing required');
}
$sql = config('database.connections.sqlsrv_testing');
config(['database.default' => 'sqlsrv_testing', 'database.connections' => ['sqlsrv_testing' => $sql], 'cache.default' => 'array', 'session.driver' => 'array', 'app.debug' => false]);
$db = app('db')->connection();
$trace('database.guard.begin');
TestDatabaseGuard::assertConnected($db);
$trace('database.ready');
$directory = realpath($argv[1]);
$root = realpath(dirname(__DIR__, 3).'/.tools');
$worker = $argv[2];
if (! $directory || ! str_starts_with($directory, $root.DIRECTORY_SEPARATOR.'discounts-race-') || ! in_array($worker, ['first', 'second', 'retry'], true)) {
    throw new RuntimeException('Invalid fixture directory');
}
$input = json_decode(file_get_contents($directory.'/input.json'), true, 512, JSON_THROW_ON_ERROR);
$traceContext = ['run_id' => basename($directory), 'scenario' => $input['mode'], 'worker' => $worker];
$mode = $input['mode'];
$which = $worker === 'first' ? 'first' : 'second';
CarbonImmutable::setTestNow('2026-11-17T20:00:00Z');
if ($worker === 'first' && $mode === 'same') {
    // Cruza un segundo tras el middleware de sesión y antes de revalidar
    // dentro del bloqueo. El reloj inmóvil ocultaría escrituras de actividad.
    $advanceClock = true;
    Event::listen(QueryExecuted::class, function (QueryExecuted $event) use (&$advanceClock): void {
        if ($advanceClock && str_starts_with($event->sql, 'SELECT id FROM user_security_locks')) {
            $advanceClock = false;
            CarbonImmutable::setTestNow('2026-11-17T20:00:02Z');
        }
    });
}
$spid = (int) $db->selectOne('SELECT @@SPID AS id')->id;
$traceContext['spid'] = $spid;
file_put_contents($directory.'/'.$worker.'-attempt.json', json_encode(['spid' => $spid]));
foreach ([TransactionBeginning::class => 'transaction.begin',
    TransactionCommitted::class => 'transaction.committed',
    TransactionRolledBack::class => 'transaction.rolled_back'] as $eventClass => $phase) {
    Event::listen($eventClass, function ($event) use ($trace, $phase, $db): void {
        if ($event->connection === $db) {
            $trace($phase, ['transaction_level' => $db->transactionLevel()]);
        }
    });
}
Event::listen(QueryExecuted::class, function ($event) use ($trace, $db): void {
    if ($event->connection === $db && $event->time >= 100) {
        $trace('query.slow.completed', ['duration_ms' => $event->time, 'fingerprint' => hash('sha256', $event->sql)]);
    }
});
$app->make(ExceptionHandler::class)->reportable(function (Throwable $exception) use ($trace): void {
    $trace('exception.reported', ['exception_type' => $exception::class]);
});
// Preparacion e inicio son estados distintos; no se abre negocio antes de start.
$ready = ['run_id' => basename($directory), 'worker' => $worker, 'pid' => getmypid(), 'spid' => $spid];
$readyFile = $directory.'/'.$worker.'-ready.json';
file_put_contents($readyFile.'.tmp', json_encode($ready, JSON_THROW_ON_ERROR));
if (! rename($readyFile.'.tmp', $readyFile)) {
    throw new RuntimeException('Cannot publish worker readiness.');
}
$trace('worker.ready');
if ($worker !== 'retry') {
    $trace('start.waiting');
    $startDeadline = $traceStarted + 45_000_000_000;
    while (! is_file($directory.'/'.$worker.'-start')) {
        if (hrtime(true) >= $startDeadline) {
            throw new RuntimeException('Worker start signal timeout.');
        }
        usleep(20000);
    }
    $trace('start.observed');
}
if ($worker === 'second') {
    $lockSignalled = false;
    $db->beforeExecuting(function (string $query) use ($trace, &$lockSignalled): void {
        if (! $lockSignalled && str_contains(strtolower($query), 'user_security_locks')) {
            $lockSignalled = true;
            $trace('security_lock.attempt');
        }
    });
}
$hold = function () use ($directory, $trace): void {
    $trace('holding.before_signal');
    file_put_contents($directory.'/holding', 'ready');
    $trace('holding.ready');
    $until = microtime(true) + 25;
    while (! is_file($directory.'/release')) {
        if (microtime(true) > $until) {
            throw new RuntimeException('Fixture timeout');
        } usleep(20000);
    }
    $trace('release.observed');
};
$handler = new TestHandler;
Log::extend('discounts-memory', fn () => new Logger('closing', [$handler]));
config(['logging.default' => 'discounts-memory', 'logging.channels.discounts-memory' => ['driver' => 'discounts-memory']]);
if ($mode === 'deadlock') {
    $db->unprepared($worker === 'first' ? 'SET DEADLOCK_PRIORITY HIGH' : 'SET DEADLOCK_PRIORITY LOW');
}
if ($worker === 'first' && $mode === 'deadlock') {
    $db->transaction(function () use ($db, $input, $hold): void {
        $row = $db->table('cash_sessions')->where('id', $input['sessionId'])->lock('with (updlock,holdlock)')->first();
        $db->table('cash_sessions')->where('id', $row->id)->update(['updated_at' => $row->updated_at]);
        $hold();
        app(IdentityAccess::class)->lock();
    });
    file_put_contents($directory.'/first-result.json', json_encode(['status' => 200, 'fixture' => true, 'codes' => []]));
    exit;
}
$armed = false;
if ($worker === 'first' && $mode === 'permission') {
    $db->transaction(function () use ($input, $hold): void {
        app(UserAdministration::class)->removeRole($input['actor'], $input['racer'], 'supervisor');
        $hold();
    });
    file_put_contents($directory.'/first-result.json', json_encode(['status' => 200, 'fixture' => true, 'codes' => []]));
    exit;
}
if ($worker === 'first') {
    $event = match ($mode) {
        'delete' => 'sales.discount.removed', 'replace' => 'sales.discount.replaced', default => 'sales.discount.applied'
    };
    Event::listen(SecurityEvent::class, function (SecurityEvent $e) use (&$armed, $event, $mode, $hold): void {
        if ($e->type !== $event && ! str_starts_with($e->type, 'sales.item_removed')) {
            return;
        }
        if ($mode === 'rollback') {
            $hold();
            throw new RuntimeException('Closing audit fixture failure');
        }
        $armed = true;
    });
    Event::listen(TransactionCommitting::class, function (TransactionCommitting $e) use (&$armed, $hold): void {
        if ($armed && $e->connection->transactionLevel() === 1) {
            $armed = false;
            $hold();
        }
    });
}
if ($worker === 'first' && $mode === 'edit_first') {
    $db->transaction(function () use ($input): void {
        $sales = app(SalesOperations::class);
        $sale = $sales->sale($input['actor'], $input['saleId']);
        $sales->changeQuantity($input['actor'], $sale->sale->id, $sale->items[0]->id, 2);
    });
    file_put_contents($directory.'/first-result.json', json_encode(['status' => 200, 'fixture' => true, 'codes' => []]));
    exit;
}
$operation = $input['operations'][$which];
$payload = $input['payloads'][$which];
$request = Request::create($operation, $input['methods'][$which], server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$input['tokens'][$which],
    'HTTP_IDEMPOTENCY_KEY' => $input['keys'][$which], ...($input['revisions'][$which] ? ['HTTP_IF_MATCH' => $input['revisions'][$which]] : [])], content: json_encode($payload, JSON_THROW_ON_ERROR));
$kernel = $app->make(HttpKernel::class);
$app->make(ExceptionHandler::class)->reportable(function (Throwable $e) use ($directory, $worker): void {
    file_put_contents($directory.'/'.$worker.'-exception.txt', (string) $e);
});
$trace('http.begin');
$response = $kernel->handle($request);
$trace('http.completed', ['http_status' => $response->getStatusCode()]);
$codes = [];
$diagnostics = [];
foreach ($handler->getRecords() as $record) {
    if (isset($record->context['exception'])) {
        $diagnostics[] = (string) $record->context['exception'];
    }
    if ($record->message === 'Retrying durable operation.') {
        $codes[] = $record->context['sql_code'];
    }
}
file_put_contents($directory.'/'.$worker.'-diagnostics.txt', implode("\n", $diagnostics));
$delivered = ! ($worker === 'first' && $mode === 'lost');
file_put_contents($directory.'/'.$worker.'-result.json', json_encode(['status' => $response->getStatusCode(), 'body' => $delivered ? json_decode($response->getContent(), true) : null, 'delivered' => $delivered, 'codes' => $codes], JSON_THROW_ON_ERROR));
$kernel->terminate($request, $response);
