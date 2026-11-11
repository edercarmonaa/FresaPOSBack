<?php

namespace Tests\Support;

use Illuminate\Database\Connection;
use RuntimeException;

final class TestDatabaseGuard
{
    public static function assertSafeName(mixed $name): string
    {
        if (! is_string($name)
            || ! preg_match('/\A[a-zA-Z][a-zA-Z0-9_]{0,127}\z/', $name)
            || strtolower($name) === 'fresapos'
            || ! str_ends_with(strtolower($name), '_test')
            || str_starts_with(strtolower($name), 'change_me')) {
            throw new RuntimeException('Base de integración insegura: se requiere un nombre propio terminado en _test y distinto de fresapos.');
        }

        return $name;
    }

    public static function assertConfigured(Connection $connection): string
    {
        $name = self::assertSafeName($connection->getConfig('database'));
        if ($connection->getDriverName() !== 'sqlsrv') {
            throw new RuntimeException('Las pruebas de integración requieren SQL Server.');
        }
        $host = $connection->getConfig('host');
        if (! is_string($host) || $host === '' || str_contains(strtolower($host), 'change_me')) {
            throw new RuntimeException('Configura explícitamente TEST_DB_HOST; no se hereda DB_HOST.');
        }

        return $name;
    }

    public static function assertConnected(Connection $connection): void
    {
        $configured = self::assertConfigured($connection);
        // Única consulta previa al DDL: comprobar la base efectiva de la sesión.
        $actual = $connection->selectOne('SELECT DB_NAME() AS database_name')->database_name ?? null;
        self::assertSafeName($actual);
        if (strcasecmp($configured, $actual) !== 0) {
            throw new RuntimeException('La base efectiva no coincide con la configuración de integración.');
        }
    }
}
