<?php

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Modules\Core\Contracts\SecurityEvent;
use Modules\Sales\Contracts\SalesOperations;
use Modules\Users\Contracts\IdentityAccess;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Tests\Support\TestDatabaseGuard;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || $app->configurationIsCached()) {
    throw new RuntimeException('Testing without cached configuration required.');
}
$sql = config('database.connections.sqlsrv_testing');
config(['database.default' => 'sqlsrv_testing', 'database.connections' => ['sqlsrv_testing' => $sql], 'cache.default' => 'array', 'session.driver' => 'array', 'app.debug' => false, 'pos.business_timezone' => 'America/Mexico_City']);
$db = app('db')->connection();
TestDatabaseGuard::assertConnected($db);
$directory = realpath($argv[1]);
$root = realpath(dirname(__DIR__, 3).'/.tools');
$worker = $argv[2];
if (! $directory || ! str_starts_with($directory, $root.DIRECTORY_SEPARATOR.'checkout-race-') || ! in_array($worker, ['first', 'second', 'retry'], true)) {
    throw new RuntimeException('Invalid fixture worker.');
}
$input = json_decode(file_get_contents($directory.'/input.json'), true, 512, JSON_THROW_ON_ERROR);
$which = $worker === 'first' ? 'first' : 'second';
if (isset($input['times'][$which])) {
    CarbonImmutable::setTestNow($input['times'][$which]);
}
$spid = (int) $db->selectOne('SELECT @@SPID AS id')->id;
file_put_contents($directory.'/'.$worker.'-attempt.json', json_encode(['spid' => $spid]));
$hold = function () use ($directory): void {
    file_put_contents($directory.'/holding', 'ready');
    $deadline = microtime(true) + 25;
    while (! is_file($directory.'/release')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Fixture timeout');
        } usleep(20000);
    }
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
$response = $kernel->handle($request);
$codes = [];
foreach ($handler->getRecords() as $r) {
    if ($r->message === 'Retrying durable operation.') {
        $codes[] = $r->context['sql_code'];
    }
}
$delivered = ! ($worker === 'first' && $input['mode'] === 'lost');
file_put_contents($directory.'/'.$worker.'-result.json', json_encode(['status' => $response->getStatusCode(), 'body' => $delivered ? json_decode($response->getContent(), true) : null, 'delivered' => $delivered, 'transient_sql_codes' => $codes], JSON_THROW_ON_ERROR));
$kernel->terminate($request, $response);
