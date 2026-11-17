<?php

namespace Modules\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Core\Contracts\ExceptionDiagnostic;
use Modules\Core\Contracts\TransactionManager;
use Modules\Core\Infrastructure\Persistence\SqlOperationStore;
use Throwable;

final class CompactOperations extends Command
{
    protected $signature = 'operations:compact';

    protected $description = 'Retira respuestas terminales expiradas; conserva claves y referencias para impedir duplicación histórica.';

    public function handle(TransactionManager $transactions, SqlOperationStore $store): int
    {
        try {
            // Máximo 500 filas por ejecución; nunca borrar pendientes o claves históricas.
            $total = 0;
            for ($batch = 0; $batch < 10; $batch++) {
                $count = $transactions->run(fn () => $store->compact(50));
                $total += $count;
                if ($count < 50) {
                    break;
                }
            }
            $this->info('Respuestas expiradas retiradas: '.$total);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            Log::error('Operation compaction failed.', ExceptionDiagnostic::context($exception));
            $this->error('No se completó la limpieza de respuestas; las claves se conservan.');

            return self::FAILURE;
        }
    }
}
