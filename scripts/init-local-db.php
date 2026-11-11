<?php

use Illuminate\Contracts\Console\Kernel;

// Solo infraestructura local; nunca ejecutar contra Azure ni con usuarios finales.
$basePath = dirname(__DIR__);
require $basePath.'/vendor/autoload.php';
$app = require $basePath.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! $app->environment('local')) {
    throw new RuntimeException('Este script solo admite APP_ENV=local.');
}

$connection = config('database.connections.sqlsrv');
$database = $connection['database'];
if (! preg_match('/\A[a-zA-Z][a-zA-Z0-9_]{0,127}\z/', $database)) {
    throw new RuntimeException('Nombre de base local inválido.');
}

config(['database.connections.sqlsrv.database' => 'master']);
$pdo = $app->make('db')->connection('sqlsrv')->getPdo();
$statement = $pdo->prepare('SELECT DB_ID(?)');
$statement->execute([$database]);
if ($statement->fetchColumn() === null) {
    $pdo->exec('CREATE DATABASE ['.$database.']');
}
echo "Base local disponible.\n";
