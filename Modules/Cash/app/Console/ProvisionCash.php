<?php

namespace Modules\Cash\Console;

use Illuminate\Console\Command;
use Modules\Cash\Application\Services\ProvisionCashRegister;

final class ProvisionCash extends Command
{
    protected $signature = 'cash:provision';

    protected $description = 'Aprovisiona idempotentemente la única caja física, sin abrir sesiones.';

    public function handle(ProvisionCashRegister $provision): int
    {
        $register = $provision->run();
        $this->info('Caja disponible: '.$register->name);

        return self::SUCCESS;
    }
}
