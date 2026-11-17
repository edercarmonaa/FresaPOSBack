<?php

namespace Tests\Support;

use Illuminate\Database\Migrations\Migration;
use Modules\Cash\Contracts\ActiveCashSession;
use Modules\Sales\Contracts\CriticalSalesWrites;
use Modules\Sales\Infrastructure\UnavailableCashSession;
use Modules\Sales\Infrastructure\UnavailableCriticalSalesWrites;

abstract class SalesSqlServerTestCase extends CatalogSqlServerTestCase
{
    private ?Migration $salesSchema = null;

    private ?Migration $operationSchema = null;

    protected function setUp(): void
    {
        parent::setUp();
        // Suite histórica de Sales sin esquema Cash: dependencia explícitamente no disponible.
        // CashSqlServerTestCase restaura el adaptador real y aplica las nuevas FK.
        $this->app->bind(ActiveCashSession::class, UnavailableCashSession::class);
        // Dependencia de la suite histórica; IdempotencySqlServerTestCase registra la real.
        $this->app->bind(CriticalSalesWrites::class, UnavailableCriticalSalesWrites::class);
        TestDatabaseGuard::assertConnected($this->database);
        $schema = require base_path('Modules/Sales/database/migrations/2026_11_13_000006_create_sales_tables.php');
        $this->database->transaction(fn () => $schema->up());
        $this->salesSchema = $schema;
        $operations = require base_path('Modules/Core/database/migrations/2026_11_17_000009_create_idempotency_records.php');
        $this->database->transaction(fn () => $operations->up());
        $this->operationSchema = $operations;
    }

    protected function tearDown(): void
    {
        try {
            while ($this->database->transactionLevel() > 0) {
                $this->database->rollBack();
            }
            if ($this->salesSchema !== null) {
                TestDatabaseGuard::assertConnected($this->database);
                $this->operationSchema?->down();
                $this->salesSchema->down();
            }
        } finally {
            parent::tearDown();
        }
    }
}
