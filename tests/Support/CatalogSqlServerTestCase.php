<?php

namespace Tests\Support;

abstract class CatalogSqlServerTestCase extends AuthSqlServerTestCase
{
    private array $catalogSchemas = [];

    private bool $ownsCatalogSchema = false;

    protected function setUp(): void
    {
        parent::setUp();
        TestDatabaseGuard::assertConnected($this->database);
        $this->catalogSchemas = [
            require base_path('Modules/Audit/database/migrations/2026_11_12_000004_add_catalog_change_data_to_security_events.php'),
            require base_path('Modules/Catalog/database/migrations/2026_11_12_000005_create_catalog_tables.php'),
        ];
        $this->database->transaction(function (): void {
            foreach ($this->catalogSchemas as $schema) {
                $schema->up();
            }
        });
        $this->ownsCatalogSchema = true;
    }

    protected function tearDown(): void
    {
        try {
            while ($this->database->transactionLevel() > 0) {
                $this->database->rollBack();
            }
            if ($this->ownsCatalogSchema) {
                TestDatabaseGuard::assertConnected($this->database);
                foreach (array_reverse($this->catalogSchemas) as $schema) {
                    $schema->down();
                }
            }
        } finally {
            parent::tearDown();
        }
    }
}
