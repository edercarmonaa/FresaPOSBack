<?php

namespace Tests\Feature;

use Illuminate\Database\Connection;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\TestDatabaseGuard;

class TestDatabaseGuardTest extends TestCase
{
    public function test_invalid_names_are_rejected_before_any_pdo_connection(): void
    {
        foreach (['fresapos', 'FRESAPOS', 'master', 'fresapos_testing', '', null, 'CHANGE_ME_test', 'other_test;USE fresapos', 'other_test ', 'other.test'] as $name) {
            $connection = new Connection(fn () => throw new LogicException('No debe abrir PDO.'), '', '', [
                'driver' => 'sqlsrv', 'host' => '.\AUDITORIA', 'database' => $name,
            ]);
            try {
                TestDatabaseGuard::assertConnected($connection);
                $this->fail('Debía rechazarse el nombre inseguro.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('Base de integración insegura', $exception->getMessage());
            }
        }
        $this->assertSame('fresapos_test', TestDatabaseGuard::assertSafeName('fresapos_test'));
    }

    public function test_actual_database_is_checked_and_must_match_configured_name(): void
    {
        $connection = new class extends Connection
        {
            public string $actualName = 'fresapos';

            public function __construct()
            {
                parent::__construct(fn () => throw new LogicException('No debe abrir PDO.'), '', '', [
                    'driver' => 'sqlsrv', 'host' => '.\AUDITORIA', 'database' => 'fresapos_test',
                ]);
            }

            public function selectOne($query, $bindings = [], $useReadPdo = true)
            {
                return (object) ['database_name' => $this->actualName];
            }
        };
        foreach (['fresapos', 'another_test'] as $actual) {
            $connection->actualName = $actual;
            try {
                TestDatabaseGuard::assertConnected($connection);
                $this->fail('Debía rechazar la base efectiva inesperada.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
        $connection->actualName = 'fresapos_test';
        TestDatabaseGuard::assertConnected($connection);
        $this->addToAssertionCount(1);
    }

    public function test_wrong_engine_and_missing_test_host_are_rejected_without_connecting(): void
    {
        foreach ([['mysql', 'localhost'], ['sqlsrv', null], ['sqlsrv', 'CHANGE_ME']] as [$driver, $host]) {
            $connection = new Connection(fn () => throw new LogicException('No debe abrir PDO.'), '', '', [
                'driver' => $driver, 'host' => $host, 'database' => 'fresapos_test',
            ]);
            try {
                TestDatabaseGuard::assertConnected($connection);
                $this->fail('Configuración insegura aceptada.');
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
