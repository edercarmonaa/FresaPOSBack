<?php

namespace Tests\Support;

use Modules\Sales\Application\Services\DurableSalesWrites;
use Modules\Sales\Contracts\CriticalSalesWrites;

abstract class IdempotencySqlServerTestCase extends CashSqlServerTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(CriticalSalesWrites::class, DurableSalesWrites::class);
    }
}
