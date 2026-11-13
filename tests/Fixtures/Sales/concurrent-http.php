<?php

// Dos kernels HTTP reales en procesos/sesiones SQL independientes, solo testing.
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Modules\Cash\Contracts\ActiveCashSession;
use Tests\Fixtures\Sales\TestCashSession;
use Tests\Support\TestDatabaseGuard;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || $app->configurationIsCached()) {
    throw new RuntimeException('Solo testing sin cache.');
}
$sql = config('database.connections.sqlsrv_testing');
config(['database.default' => 'sqlsrv_testing', 'database.connections' => ['sqlsrv_testing' => $sql], 'cache.default' => 'array', 'session.driver' => 'array']);
$db = app('db')->connection();
TestDatabaseGuard::assertConnected($db);
$directory = realpath($argv[1]);
$root = realpath(dirname(__DIR__, 3).'/.tools');
if (! $directory || ! str_starts_with($directory, $root.DIRECTORY_SEPARATOR.'sales-http-race-')) {
    throw new RuntimeException('Ruta de fixtures inválida.');
}
$input = json_decode(file_get_contents($directory.'/input.json'), true, 512, JSON_THROW_ON_ERROR);
$worker = $argv[2];
if (! in_array($worker, ['first', 'second'], true)) {
    throw new RuntimeException('Worker inválido.');
}
config(['app.key' => $input['app_key'], 'app.debug' => false]);
if (($input['mode'] ?? '') !== 'blocked_open') {
    $app->instance(ActiveCashSession::class, new TestCashSession($input['cash']));
}
file_put_contents($directory.'/'.$worker.'-attempt.json', json_encode(['spid' => (int) $db->selectOne('SELECT @@SPID AS id')->id]));
try {
    $db->beginTransaction();
    $request = Request::create('/api/v1/sales'.$input['path'], $input['method'], server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer '.$input['token'], 'HTTP_IF_MATCH' => '"'.$input['revision'].'"', 'HTTP_IDEMPOTENCY_KEY' => 'concurrent-open-key-01', 'HTTP_X_REQUEST_ID' => 'http-race-'.$worker], content: json_encode($input['payloads'][$worker], JSON_THROW_ON_ERROR));
    $kernel = $app->make(HttpKernel::class);
    $response = $kernel->handle($request);
    if ($worker === 'first') {
        file_put_contents($directory.'/holding', 'ready');
        $deadline = microtime(true) + 20;
        while (! is_file($directory.'/release')) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Timeout de commit.');
            }
            usleep(20000);
        }
    }
    $db->commit();
    file_put_contents($directory.'/'.$worker.'-result.json', json_encode(['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true)], JSON_THROW_ON_ERROR));
    $kernel->terminate($request, $response);
} finally {
    while ($db->transactionLevel() > 0) {
        $db->rollBack();
    }
}
