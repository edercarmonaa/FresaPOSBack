<?php

namespace Tests\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use RuntimeException;
use Tests\Fixtures\Persistence\ProbeMigration;
use Tests\Fixtures\Persistence\ProbeRecord;
use Tests\TestCase;

abstract class SqlServerTestCase extends TestCase
{
    protected Connection $database;

    protected ?ProbeMigration $probeSchema = null;

    protected function setUp(): void
    {
        parent::setUp();
        if ($this->app->configurationIsCached()
            || ! $this->app->environment('testing')
            || config('database.default') !== 'sqlsrv_testing') {
            throw new RuntimeException('Integración requiere testing, sqlsrv_testing y configuración sin cachear.');
        }
        // En memoria, eliminar incluso la posibilidad de elegir la conexión local sqlsrv.
        $configuration = config('database.connections.sqlsrv_testing');
        config(['database.connections' => ['sqlsrv_testing' => $configuration]]);
        $manager = $this->app->make('db');
        foreach (array_keys($manager->getConnections()) as $name) {
            $manager->purge($name);
        }
        $this->database = $manager->connection('sqlsrv_testing');
        TestDatabaseGuard::assertConnected($this->database);
        $prefix = 'probe_'.bin2hex(random_bytes(6));
        $this->probeSchema = new ProbeMigration($this->database, $prefix.'_parents', $prefix.'_children');
        try {
            $this->probeSchema->up();
        } catch (\Throwable $exception) {
            $this->probeSchema->down();
            $this->probeSchema = null;
            throw $exception;
        }
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->database)) {
                while ($this->database->transactionLevel() > 0) {
                    $this->database->rollBack();
                }
            }
            $this->probeSchema?->down();
        } finally {
            parent::tearDown();
        }
    }

    protected function createRecord(string $reference = 'probe'): ProbeRecord
    {
        $record = new ProbeRecord;
        $record->setTable($this->probeSchema->parents);
        $record->fill([
            'reference' => $reference,
            'amount' => '1234.5600',
            'occurred_at' => CarbonImmutable::parse('2026-01-01 12:00:00', 'America/Mexico_City')->utc(),
        ])->save();

        return $record;
    }
}
