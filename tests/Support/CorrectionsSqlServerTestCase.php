<?php

namespace Tests\Support;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Str;
use Modules\Sales\Contracts\SaleCheckout;
use Modules\Sales\Contracts\SaleCorrections;
use Modules\Sales\Contracts\SaleSnapshot;

abstract class CorrectionsSqlServerTestCase extends DiscountsSqlServerTestCase
{
    private ?Migration $correctionSchema = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->correctionSchema = require base_path('Modules/Sales/database/migrations/2026_11_19_000017_enable_paid_sale_corrections.php');
        $this->database->transaction(fn () => $this->correctionSchema->up());
    }

    protected function tearDown(): void
    {
        try {
            while ($this->database->transactionLevel() > 0) {
                $this->database->rollBack();
            }
            TestDatabaseGuard::assertConnected($this->database);
            $this->correctionSchema?->down();
        } finally {
            parent::tearDown();
        }
    }

    protected function settledSale(bool $table = false): SaleSnapshot
    {
        $sale = $this->sale($table);

        return app(SaleCheckout::class)->confirm($this->actor, $sale->sale->id, [$this->payment()], (string) Str::uuid(), $this->revision($sale));
    }

    protected function reopen(SaleSnapshot $sale, ?string $observation = null, ?string $key = null): array
    {
        return app(SaleCorrections::class)->write($this->actor, $sale->sale->id, 'reopen', compact('observation'), $key ?? (string) Str::uuid(), $this->revision($sale));
    }

    protected function correction(array $row, string $action, array $input = [], ?string $key = null): array
    {
        return app(SaleCorrections::class)->write($this->actor, $row['sale_id'], $action, $input, $key ?? (string) Str::uuid(), '"'.$row['revision'].'"');
    }
}
