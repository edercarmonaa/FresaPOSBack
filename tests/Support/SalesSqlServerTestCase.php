<?php

namespace Tests\Support;

use Illuminate\Database\Migrations\Migration;

abstract class SalesSqlServerTestCase extends CatalogSqlServerTestCase
{
    private ?Migration $salesSchema = null;

    protected function setUp(): void
    {
        parent::setUp();
        TestDatabaseGuard::assertConnected($this->database);
        $schema = require base_path('Modules/Sales/database/migrations/2026_11_13_000006_create_sales_tables.php');
        $this->database->transaction(fn () => $schema->up());
        $this->salesSchema = $schema;
    }

    protected function tearDown(): void
    {
        try {
            while ($this->database->transactionLevel() > 0) {
                $this->database->rollBack();
            }
            if ($this->salesSchema !== null) {
                TestDatabaseGuard::assertConnected($this->database);
                $this->salesSchema->down();
            }
        } finally {
            parent::tearDown();
        }
    }
}
