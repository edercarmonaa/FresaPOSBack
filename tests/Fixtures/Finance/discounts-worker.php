<?php

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitting;
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

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || $app->configurationIsCached()) {
    throw new RuntimeException('Uncached testing required');
}
$sql = config('database.connections.sqlsrv_testing');
config(['database.default' => 'sqlsrv_testing', 'database.connections' => ['sqlsrv_testing' => $sql], 'cache.default' => 'array', 'session.driver' => 'array', 'app.debug' => false]);
$db = app('db')->connection();
TestDatabaseGuard::assertConnected($db);
$directory = realpath($argv[1]);
$root = realpath(dirname(__DIR__, 3).'/.tools');
$worker = $argv[2];
if (! $directory || ! str_starts_with($directory, $root.DIRECTORY_SEPARATOR.'discounts-race-') || ! in_array($worker, ['first', 'second', 'retry'], true)) {
    throw new RuntimeException('Invalid fixture directory');
}
$input = json_decode(file_get_contents($directory.'/input.json'), true, 512, JSON_THROW_ON_ERROR);
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
file_put_contents($directory.'/'.$worker.'-attempt.json', json_encode(['spid' => $spid]));
$hold = function () use ($directory): void {
    file_put_contents($directory.'/holding', 'ready');
    $until = microtime(true) + 25;
    while (! is_file($directory.'/release')) {
        if (microtime(true) > $until) {
            throw new RuntimeException('Fixture timeout');
        } usleep(20000);
    }
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
$response = $kernel->handle($request);
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
