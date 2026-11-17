<?php

namespace Modules\Cash\Infrastructure\Seeders;

use Illuminate\Database\Seeder;
use Modules\Cash\Application\Services\ProvisionCashRegister;

final class CashRegisterSeeder extends Seeder
{
    public function run(): void
    {
        app(ProvisionCashRegister::class)->run();
    }
}
