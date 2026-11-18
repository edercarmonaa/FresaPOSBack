<?php

namespace Tests\Support;

use Illuminate\Support\Str;
use Modules\Cash\Contracts\CashClosings;
use Modules\Sales\Contracts\SaleCheckout;

abstract class ReconciliationSqlServerTestCase extends CheckoutSqlServerTestCase
{
    private array $closingSchemas = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->closingSchemas = [
            require base_path('Modules/Cash/database/migrations/2026_11_18_000013_create_cash_reconciliations.php'),
            require base_path('Modules/Sales/database/migrations/2026_11_18_000014_guard_closed_cash_sessions.php'),
        ];
        $this->database->transaction(function (): void {
            foreach ($this->closingSchemas as $schema) {
                $schema->up();
            }
        });
    }

    protected function tearDown(): void
    {
        try {
            while ($this->database->transactionLevel() > 0) {
                $this->database->rollBack();
            }
            TestDatabaseGuard::assertConnected($this->database);
            foreach (array_reverse($this->closingSchemas) as $schema) {
                $schema->down();
            }
        } finally {
            parent::tearDown();
        }
    }

    protected function closeCount(string $counted = '100', ?string $key = null, string $observation = 'Conteo físico verificado'): array
    {
        return app(CashClosings::class)->close($this->actor, $this->sessionId, $counted, $observation, $key ?? (string) Str::uuid());
    }

    protected function paid(array $payments = [], bool $table = false): void
    {
        $sale = $this->sale($table);
        app(SaleCheckout::class)->confirm($this->actor, $sale->sale->id, $payments ?: [$this->payment()], (string) Str::uuid(), $this->revision($sale));
    }
}
