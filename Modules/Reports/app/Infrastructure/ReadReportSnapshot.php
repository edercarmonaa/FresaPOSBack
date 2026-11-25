<?php

namespace Modules\Reports\Infrastructure;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Core\Contracts\ApiCode;
use Modules\Core\Contracts\OperationRejected;
use Modules\Core\Contracts\TransactionManager;

/** Aislamiento por conexión, restaurado siempre. Render/export nunca se ejecutan bajo locks. */
final class ReadReportSnapshot
{
    public function __construct(private readonly TransactionManager $transactions) {}

    public function run(Closure $read): mixed
    {
        $db = DB::connection();
        if ($db->transactionLevel() !== 0) {
            throw new \LogicException('El reporte requiere su propia frontera de lectura.');
        }
        $options = $db->select('DBCC USEROPTIONS');
        $prior = [];
        foreach ($options as $option) {
            $row = (array) $option;
            $prior[strtolower((string) reset($row))] = (string) end($row);
        }
        $isolation = strtoupper($prior['isolation level'] ?? 'READ COMMITTED');
        if ($isolation === 'READ COMMITTED SNAPSHOT') {
            $isolation = 'READ COMMITTED';
        }
        if (! in_array($isolation, ['READ UNCOMMITTED', 'READ COMMITTED', 'REPEATABLE READ', 'SERIALIZABLE', 'SNAPSHOT'], true)) {
            throw new \LogicException('Aislamiento no reconocido.');
        }
        $timeout = (int) $db->selectOne('SELECT @@LOCK_TIMEOUT AS value')->value;
        $priority = (int) $db->selectOne('SELECT deadlock_priority FROM sys.dm_exec_sessions WHERE session_id=@@SPID')->deadlock_priority;
        $snapshot = (int) $db->selectOne('SELECT snapshot_isolation_state FROM sys.databases WHERE database_id=DB_ID()')->snapshot_isolation_state === 1;
        $level = $snapshot ? 'SNAPSHOT' : 'SERIALIZABLE';
        try {
            $db->unprepared('SET LOCK_TIMEOUT 5000');
            $db->unprepared('SET DEADLOCK_PRIORITY LOW');

            return $this->transactions->run(function () use ($db, $level, $read): mixed {
                // Lote directo: un SET preparado puede quedar limitado al alcance RPC.
                // Se ejecuta después de BEGIN y antes de cualquier lectura de datos.
                $db->unprepared('SET TRANSACTION ISOLATION LEVEL '.$level);

                return $read($level);
            });
        } catch (QueryException $exception) {
            if (in_array((int) ($exception->errorInfo[1] ?? 0), [1205, 1222], true)) {
                throw new OperationRejected(ApiCode::SERVICE_UNAVAILABLE);
            }
            throw $exception;
        } finally {
            $db->unprepared('SET DEADLOCK_PRIORITY '.$priority);
            $db->unprepared('SET TRANSACTION ISOLATION LEVEL '.$isolation);
            $db->unprepared('SET LOCK_TIMEOUT '.$timeout);
        }
    }
}
