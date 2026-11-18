<?php

use Illuminate\Contracts\Console\Kernel;
use Tests\Support\FinancialIntegrity;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $report = FinancialIntegrity::inspect(app('db')->connection());
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
    exit($report['passed'] ? 0 : 1);
} catch (Throwable $exception) {
    // La CLI tampoco publica consultas, valores o credenciales ante un fallo.
    fwrite(STDERR, 'No se pudo completar la inspección financiera; revisar el entorno autorizado.'.PHP_EOL);
    exit(2);
}
