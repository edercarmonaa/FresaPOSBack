<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\DatabaseManager;
use Tests\Support\TestDatabaseGuard;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
if ($app->configurationIsCached()) {
    throw new RuntimeException('No preparar pruebas con configuración cacheada.');
}
$app->loadEnvironmentFrom('.env.testing');
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || ! is_file($app->environmentFilePath())) {
    throw new RuntimeException('Se requiere .env.testing y APP_ENV=testing.');
}
$manager = $app->make(DatabaseManager::class);
$connection = $manager->connection('sqlsrv_testing');
$database = TestDatabaseGuard::assertConfigured($connection);
$server = explode('\\', strtolower((string) $connection->getConfig('host')));
if (count($server) > 2 || ! in_array($server[0], ['.', '(local)', 'localhost', '127.0.0.1'], true)) {
    throw new RuntimeException('Este script solo aprovisiona una instancia SQL local.');
}
$adminConfig = $connection->getConfig();
$adminConfig['database'] = 'master';
config(['database.connections.sqlsrv_test_admin' => $adminConfig]);
$admin = $manager->connection('sqlsrv_test_admin');
$existing = $admin->selectOne('SELECT DB_ID(?) AS database_id', [$database]);
if ($existing->database_id === null) {
    // Nombre validado antes de interpolar un identificador; nunca usa DB_DATABASE.
    $admin->statement('CREATE DATABASE ['.$database.']');
}
TestDatabaseGuard::assertConnected($connection);
echo "Base de pruebas disponible; no se modificó fresapos.\n";
