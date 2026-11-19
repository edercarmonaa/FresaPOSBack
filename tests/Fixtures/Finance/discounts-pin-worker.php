<?php

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Modules\Auth\Application\EmployeeAccess;
use Modules\Auth\Contracts\AuthenticatedRequestAccess;
use Modules\Auth\Domain\ExpiredSession;
use Modules\Core\Contracts\SecurityEvent;
use Modules\Sales\Application\Services\CoordinateSalesWrite;
use Modules\Sales\Contracts\CriticalSalesWrites;
use Modules\Sales\Contracts\DiscountProposal;
use Modules\Sales\Contracts\SalesOperations;
use Tests\Support\TestDatabaseGuard;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || $app->configurationIsCached()) {
    throw new RuntimeException('Uncached testing required');
}
$sql = config('database.connections.sqlsrv_testing');
config(['database.default' => 'sqlsrv_testing', 'database.connections' => ['sqlsrv_testing' => $sql], 'cache.default' => 'array', 'session.driver' => 'array']);
$db = app('db')->connection();
TestDatabaseGuard::assertConnected($db);
$directory = realpath($argv[1]);
$root = realpath(dirname(__DIR__, 3).'/.tools');
$worker = $argv[2];
if (! $directory || ! str_starts_with($directory, $root.DIRECTORY_SEPARATOR.'discounts-pin-') || ! in_array($worker, ['pin', 'discount'], true)) {
    throw new RuntimeException('Invalid worker directory');
}
$input = json_decode(file_get_contents($directory.'/input.json'), true, 512, JSON_THROW_ON_ERROR);
CarbonImmutable::setTestNow('2026-11-17T20:00:00Z');
Auth::guard('web')->loginUsingId($input['actor']);
$session = app('session')->driver('array');
$session->setId($input['webSession']);
$session->start();
$request = Request::create('/pos');
$request->setLaravelSession($session);
$wait = function (string $name) use ($directory): void {
    $until = microtime(true) + 25;
    while (! is_file($directory.'/'.$name)) {
        if (microtime(true) > $until) {
            throw new RuntimeException('PIN barrier timeout');
        }usleep(20000);
    }
};
file_put_contents($directory.'/'.$worker.'-spid.json', json_encode(['spid' => (int) $db->selectOne('SELECT @@SPID AS id')->id]));
if ($worker === 'pin') {
    $armed = false;
    Event::listen(SecurityEvent::class, function (SecurityEvent $event) use (&$armed, $input): void {
        if ($event->type === 'login' && $event->actorId === $input['employee']) {
            $armed = true;
        }
    });
    Event::listen(TransactionCommitting::class, function (TransactionCommitting $event) use (&$armed, $directory, $wait): void {
        if ($armed && $event->connection->transactionLevel() === 1) {
            $armed = false;
            file_put_contents($directory.'/holding', 'ready');
            $wait('release');
        }
    });
    $employee = app(EmployeeAccess::class)->switchEmployee($request, 'discount.pin.racer', '0042');
    file_put_contents($directory.'/pin-result.json', json_encode(['actor' => $employee->id]));
} else {
    $access = fn () => app(AuthenticatedRequestAccess::class)->requireWeb($request, $input['actor']);
    $access();
    file_put_contents($directory.'/validated', 'ready');
    $wait('execute');
    try {
        app(CriticalSalesWrites::class)->run($input['actor'], 'sales.'.$input['saleId'].'.discount', $input['key'], ['type' => 'percent', 'value' => 20, 'revision' => $input['revision']],
            fn () => app(CoordinateSalesWrite::class)->run($input['actor'], $input['saleId'], false, $input['revision'], fn () => app(SalesOperations::class)->setDiscount($input['actor'], $input['saleId'], new DiscountProposal('percent', 20))), access: $access);
        $status = 'incorrectly_applied';
    } catch (ExpiredSession) {
        $status = 'revoked_session_rejected';
    }
    file_put_contents($directory.'/discount-result.json', json_encode(['status' => $status]));
}
