<?php

// Kernel HTTP y SQL reales. Sin conexiones operativas ni adaptadores de negocio ficticios.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Modules\Core\Contracts\SecurityEvent;
use Modules\Sales\Application\Services\DurableSalesWrites;
use Modules\Sales\Contracts\CriticalSalesWrites;
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
if (! $directory || ! str_starts_with($directory, $root.DIRECTORY_SEPARATOR.'idempotency-race-')) {
    throw new RuntimeException('Ruta de fixture inválida.');
}
$input = json_decode(file_get_contents($directory.'/input.json'), true, 512, JSON_THROW_ON_ERROR);
$worker = $argv[2];
if (! in_array($worker, ['first', 'second', 'retry', 'status'], true)) {
    throw new RuntimeException('Worker inválido.');
}
$app->bind(CriticalSalesWrites::class, DurableSalesWrites::class);
$armed = false;
$committing = false;
$hold = function () use ($directory): void {
    file_put_contents($directory.'/holding', 'ready');
    $deadline = microtime(true) + 25;
    while (! is_file($directory.'/release')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Timeout de fixture.');
        }usleep(20000);
    }
};
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
            if ($event->type === 'sales.opened') {
                $hold();
                throw new RuntimeException('Fixture rollback before commit.');
            }
        });
    }
    if ($input['mode'] === 'after_commit') {
        Event::listen(TransactionCommitted::class, function (TransactionCommitted $event) use (&$committing): void {
            if ($committing && $event->connection->transactionLevel() === 0) {
                $committing = false;
                throw new RuntimeException('Fixture failure after real SQL commit.');
            }
        });
    }
}
file_put_contents($directory.'/'.$worker.'-attempt.json', json_encode(['spid' => (int) $db->selectOne('SELECT @@SPID AS id')->id]));
$which = $worker === 'first' ? 'first' : 'second';
$key = $input['keys'][$which];
$path = $input['path'];
$method = $input['method'];
$body = $input['payloads'][$which];
if ($worker === 'status') {
    $path = '/operations/'.$input['keys']['first'].'/status';
    $method = 'GET';
    $body = [];
}
$request = Request::create('/api/v1'.$path, $method, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$input['token'], 'HTTP_IDEMPOTENCY_KEY' => $key,
    'HTTP_IF_MATCH' => $input['revision'] === null ? '' : '"'.$input['revision'].'"', 'HTTP_X_REQUEST_ID' => 'durable-race-'.$worker], content: json_encode($body, JSON_THROW_ON_ERROR));
$kernel = $app->make(HttpKernel::class);
$response = $kernel->handle($request);
$result = ['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true), 'delivered' => true];
if ($input['mode'] === 'lost' && $worker === 'first') {
    $result = ['status' => $response->getStatusCode(), 'delivered' => false];
}
file_put_contents($directory.'/'.$worker.'-result.json', json_encode($result, JSON_THROW_ON_ERROR));
$kernel->terminate($request, $response);
echo json_encode(['status' => $response->getStatusCode(), 'delivered' => $result['delivered']], JSON_THROW_ON_ERROR).PHP_EOL;
