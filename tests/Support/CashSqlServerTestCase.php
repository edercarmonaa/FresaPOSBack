<?php

namespace Tests\Support;

use Modules\Cash\Application\Services\ManageCash;
use Modules\Cash\Contracts\ActiveCashSession;

abstract class CashSqlServerTestCase extends SalesSqlServerTestCase
{
    private array $cashSchemas = [];

    protected function setUp(): void
    {
        parent::setUp();
        TestDatabaseGuard::assertConnected($this->database);
        $this->cashSchemas = [
            require base_path('Modules/Cash/database/migrations/2026_11_17_000007_create_cash_tables.php'),
            require base_path('Modules/Sales/database/migrations/2026_11_17_000008_link_sales_to_cash_sessions.php'),
        ];
        $this->database->transaction(function (): void {
            foreach ($this->cashSchemas as $schema) {
                $schema->up();
            }
        });
        $this->app->bind(ActiveCashSession::class, ManageCash::class);
    }

    protected function tearDown(): void
    {
        try {
            while ($this->database->transactionLevel() > 0) {
                $this->database->rollBack();
            }
            TestDatabaseGuard::assertConnected($this->database);
            foreach (array_reverse($this->cashSchemas) as $schema) {
                $schema->down();
            }
        } finally {
            parent::tearDown();
        }
    }
}
