<?php

namespace Modules\Catalog\Console;

use Illuminate\Console\Command;
use Modules\Catalog\Application\Ports\ProductImageFiles;
use Modules\Catalog\Application\Services\ManageProductImages;

class ReconcileProductImages extends Command
{
    protected $signature = 'catalog:images:reconcile {--apply : Retira únicamente archivos sin referencias SQL}';

    protected $description = 'Revisa intenciones de imagen antiguas; solo modifica archivos al indicar --apply.';

    public function handle(ProductImageFiles $files, ManageProductImages $images): int
    {
        $before = now()->subHours(max(1, (int) config('catalog.images.recovery_age_hours')))->timestamp;
        $count = 0;
        $failed = 0;
        foreach ($files->pending($before) as $change) {
            $count++;
            $this->line('Cambio pendiente: '.$change->id);
            if ($this->option('apply') && ! $images->recover($change)) {
                $failed++;
            }
        }
        $this->info($this->option('apply') ? "Revisados: $count. Pendientes por fallo: $failed." : "Simulación: $count cambios antiguos. Usa --apply para reconciliar con SQL.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
