<?php

use Illuminate\Contracts\Console\Kernel;

/** Compara una reconstrucción en memoria contra un manifiesto histórico privado. Nunca escribe SQL. */
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    if (count($argv) !== 3) {
        throw new RuntimeException('Two private JSON evidence files are required.');
    }
    $reference = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
    $candidates = json_decode(file_get_contents($argv[2]), true, 512, JSON_THROW_ON_ERROR);
    $database = app('db')->connection();
    if ($database->selectOne('SELECT DB_NAME() AS name')->name !== 'fresapos' || $reference['database'] !== 'fresapos') {
        throw new RuntimeException('Read-only operative inspection requires fresapos.');
    }
    $allowed = ['audit_security_events', 'auth_access_sessions', 'cash_reconciliations', 'cash_sessions', 'idempotency_records'];
    if (array_diff(array_keys($candidates), $allowed) || array_diff($allowed, array_keys($candidates))) {
        throw new RuntimeException('Exactly the five observed tables are required.');
    }
    $report = ['database' => 'fresapos', 'captured_at_utc' => gmdate('c'), 'sql_writes' => false, 'tables' => []];
    foreach ($allowed as $table) {
        $historical = $reference['tables'][$table];
        $actualColumns = array_column($database->select('SELECT name FROM sys.columns WHERE object_id=OBJECT_ID(?) ORDER BY column_id', [$table]), 'name');
        if ($historical['columns'] !== $actualColumns) {
            throw new RuntimeException('The projected schema changed; inspect it first.');
        }
        $changes = $candidates[$table];
        $rows = $database->table($table)->select($historical['columns'])->get()->all();
        $reconstructed = [];
        foreach ($rows as $row) {
            if (in_array($row->id, $changes['additions'], true)) {
                continue;
            }
            foreach ($changes['previous_fields'][$row->id] ?? [] as $column => $value) {
                if (! in_array($column, $historical['columns'], true) || $column === 'id' || (! is_scalar($value) && $value !== null)) {
                    throw new RuntimeException('Invalid in-memory candidate.');
                }
                $row->{$column} = $value;
            }
            $reconstructed[] = json_encode($row, JSON_THROW_ON_ERROR);
        }
        sort($reconstructed, SORT_STRING);
        $matches = count($reconstructed) === $historical['count'] && hash_equals($historical['digest'], hash('sha256', json_encode($reconstructed, JSON_THROW_ON_ERROR)));
        $report['tables'][] = ['table' => $table, 'historical_rows' => $historical['count'], 'current_rows' => count($rows), 'candidate_additions' => count($changes['additions']), 'candidate_updated_rows' => count($changes['previous_fields']), 'historical_sha256_matches' => $matches];
    }
    $report['passed'] = ! in_array(false, array_column($report['tables'], 'historical_sha256_matches'), true);
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
    exit($report['passed'] ? 0 : 1);
} catch (Throwable) {
    fwrite(STDERR, 'No se pudo comprobar la evidencia histórica privada. Revisar archivos y esquema autorizados.'.PHP_EOL);
    exit(2);
}
