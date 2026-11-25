<?php

namespace Tests\Support;

use Illuminate\Database\Connection;

final class SqlReportPlan
{
    /** Plan estimado de la consulta equivalente con literales escapados; exclusivamente en _test. */
    public static function capture(Connection $db, string $sql, array $bindings): string
    {
        TestDatabaseGuard::assertConnected($db);
        $pdo = $db->getPdo();
        $values = $db->prepareBindings($bindings);
        $parts = explode('?', $sql);
        if (count($parts) !== count($values) + 1 || ! str_starts_with(strtolower(ltrim($sql)), 'select ')) {
            throw new \InvalidArgumentException('Consulta de lectura inválida.');
        }
        $literal = array_shift($parts);
        foreach ($values as $i => $value) {
            $quoted = $value === null ? 'NULL' : $pdo->quote((string) $value);
            if ($quoted === false) {
                throw new \RuntimeException('No se pudo escapar el parámetro de QA.');
            }
            $literal .= $quoted.$parts[$i];
        }
        $prior = $pdo->getAttribute(\PDO::SQLSRV_ATTR_DIRECT_QUERY);
        $statement = null;
        $xml = '';
        $pdo->setAttribute(\PDO::SQLSRV_ATTR_DIRECT_QUERY, true);
        try {
            $pdo->exec('SET SHOWPLAN_XML ON');
            $statement = $pdo->query($literal);
            do {
                if ($statement->columnCount() > 0) {
                    $value = $statement->fetchColumn();
                    if (is_resource($value)) {
                        $value = stream_get_contents($value);
                    }if (is_string($value) && str_contains($value, 'ShowPlanXML')) {
                        $xml = $value;
                        break;
                    }
                }
            } while ($statement->nextRowset());
        } finally {
            $statement?->closeCursor();
            $pdo->exec('SET SHOWPLAN_XML OFF');
            $pdo->setAttribute(\PDO::SQLSRV_ATTR_DIRECT_QUERY, $prior);
        }
        if ($xml === '') {
            throw new \RuntimeException('No se obtuvo el plan SQL de pruebas.');
        }

        return $xml;
    }
}
