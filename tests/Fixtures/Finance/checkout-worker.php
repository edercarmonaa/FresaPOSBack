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
    throw new RuntimeException('Testing without cached configuration required.');
}
$sql = config('database.connections.sqlsrv_testing');
config(['database.default' => 'sqlsrv_testing', 'database.connections' => ['sqlsrv_testing' => $sql], 'cache.default' => 'array', 'session.driver' => 'array', 'app.debug' => false, 'pos.business_timezone' => 'America/Mexico_City']);
$db = app('db')->connection();
$trace('database.guard.begin');
TestDatabaseGuard::assertConnected($db);
$trace('database.ready');
$directory = realpath($argv[1]);
$root = realpath(dirname(__DIR__, 3).'/.tools');
$worker = $argv[2];
if (! $directory || ! str_starts_with($directory, $root.DIRECTORY_SEPARATOR.'checkout-race-') || ! in_array($worker, ['first', 'second', 'retry'], true)) {
    throw new RuntimeException('Invalid fixture worker.');
}
$input = json_decode(file_get_contents($directory.'/input.json'), true, 512, JSON_THROW_ON_ERROR);
$traceContext = ['run_id' => basename($directory), 'scenario' => $input['mode'], 'worker' => $worker];
$which = $worker === 'first' ? 'first' : 'second';
if (isset($input['times'][$which])) {
    CarbonImmutable::setTestNow($input['times'][$which]);
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
    $deadline = microtime(true) + 25;
    while (! is_file($directory.'/release')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Fixture timeout');
        } usleep(20000);
    }
    $trace('release.observed');
};
$handler = new TestHandler;
Log::extend('checkout-memory', fn () => new Logger('checkout', [$handler]));
config(['logging.default' => 'checkout-memory', 'logging.channels.checkout-memory' => ['driver' => 'checkout-memory']]);
if ($input['mode'] === 'deadlock') {
    $db->unprepared($worker === 'first' ? 'SET DEADLOCK_PRIORITY HIGH' : 'SET DEADLOCK_PRIORITY LOW');
}
if ($worker === 'first' && in_array($input['mode'], ['edit', 'session', 'deadlock'], true)) {
    $db->transaction(function () use ($db, $input, $hold): void {
        if ($input['mode'] === 'deadlock') {
            $db->table('sales_daily_sequences')->where('business_date', '2026-11-17')->update(['last_value' => 1]);
            $hold();
            app(IdentityAccess::class)->lock();
        } else {
            app(IdentityAccess::class)->lock();
            if ($input['mode'] === 'edit') {
                $sales = app(SalesOperations::class);
                $sale = $sales->sale($input['actor'], $input['ids']['first']);
                $sales->changeQuantity($input['actor'], $sale->sale->id, $sale->items[0]->id, 2);
            } else {
                $db->table('cash_registers')->lock('with (updlock,holdlock)')->first();
                $db->table('cash_sessions')->where('id', $input['sessionId'])->update(['status' => 'closed', 'closed_at' => now()->utc(), 'closed_by' => $input['actor']]);
            }
            $hold();
        }
    });
    file_put_contents($directory.'/first-result.json', json_encode(['status' => 200, 'fixture_transition' => true]));
    exit;
}
$armed = false;
if ($worker === 'first') {
    Event::listen(SecurityEvent::class, function (SecurityEvent $e) use (&$armed, $input, $hold): void {
        if ($e->type === 'sales.checkout.confirmed') {
            if ($input['mode'] === 'rollback') {
                $hold();
                throw new RuntimeException('Fixture audit rollback');
            }
            $armed = true;
        }
    });
    Event::listen(TransactionCommitting::class, function (TransactionCommitting $e) use (&$armed, $hold): void {
        if ($armed && $e->connection->transactionLevel() === 1) {
            $armed = false;
            $hold();
        }
    });
}
$request = Request::create('/api/v1/sales/'.$input['ids'][$which].'/checkout', 'POST', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$input['token'], 'HTTP_IDEMPOTENCY_KEY' => $input['keys'][$which], 'HTTP_IF_MATCH' => $input['revisions'][$which]], content: json_encode(['payments' => $input['payments'][$which]], JSON_THROW_ON_ERROR));
$kernel = $app->make(HttpKernel::class);
$trace('http.begin');
$response = $kernel->handle($request);
$trace('http.completed', ['http_status' => $response->getStatusCode()]);
$codes = [];
foreach ($handler->getRecords() as $r) {
    if ($r->message === 'Retrying durable operation.') {
        $codes[] = $r->context['sql_code'];
    }
}
$delivered = ! ($worker === 'first' && $input['mode'] === 'lost');
file_put_contents($directory.'/'.$worker.'-result.json', json_encode(['status' => $response->getStatusCode(), 'body' => $delivered ? json_decode($response->getContent(), true) : null, 'delivered' => $delivered, 'transient_sql_codes' => $codes], JSON_THROW_ON_ERROR));
$kernel->terminate($request, $response);
