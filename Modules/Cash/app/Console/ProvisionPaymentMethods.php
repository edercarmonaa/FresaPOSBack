<?php

namespace Modules\Cash\Console;

use Illuminate\Console\Command;
use Modules\Cash\Application\Services\ManagePaymentMethods;

final class ProvisionPaymentMethods extends Command
{
    protected $signature = 'cash:payment-methods:provision';

    protected $description = 'Aprovisiona los cuatro métodos estables sin reactivar ni sobrescribir existentes.';

    public function handle(ManagePaymentMethods $methods): int
    {
        $methods->provision();
        $this->info('Métodos de pago aprovisionados. Sin pagos ni movimientos.');

        return self::SUCCESS;
    }
}
