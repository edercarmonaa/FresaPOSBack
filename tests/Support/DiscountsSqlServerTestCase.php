<?php

namespace Tests\Support;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Str;
use Modules\Sales\Application\Services\CoordinateSalesWrite;
use Modules\Sales\Contracts\CriticalSalesWrites;
use Modules\Sales\Contracts\DiscountApproval;
use Modules\Sales\Contracts\DiscountProposal;
use Modules\Sales\Contracts\SaleSnapshot;
use Modules\Sales\Contracts\SalesOperations;
use Modules\Sales\Infrastructure\AuthorizedDiscounts;

abstract class DiscountsSqlServerTestCase extends ReasonsSqlServerTestCase
{
    private ?Migration $discountSchema = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->discountSchema = require base_path('Modules/Sales/database/migrations/2026_11_19_000016_enable_authorized_sale_discounts.php');
        $this->database->transaction(fn () => $this->discountSchema->up());
        $this->app->bind(DiscountApproval::class, AuthorizedDiscounts::class);
    }

    protected function tearDown(): void
    {
        try {
            while ($this->database->transactionLevel() > 0) {
                $this->database->rollBack();
            }
            TestDatabaseGuard::assertConnected($this->database);
            $this->discountSchema?->down();
        } finally {
            parent::tearDown();
        }
    }

    protected function discount(SaleSnapshot $sale, ?DiscountProposal $proposal, ?string $key = null, ?string $actor = null): SaleSnapshot
    {
        $actor ??= $this->actor;
        $action = $proposal === null ? 'discount-remove' : 'discount';
        $payload = ($proposal === null ? [] : ['type' => $proposal->type, 'value' => $proposal->value]) + ['revision' => $this->revision($sale)];

        return app(CriticalSalesWrites::class)->run($actor, 'sales.'.$sale->sale->id.'.'.$action, $key ?? (string) Str::uuid(), $payload,
            fn () => app(CoordinateSalesWrite::class)->run($actor, $sale->sale->id, false, $this->revision($sale),
                fn () => app(SalesOperations::class)->setDiscount($actor, $sale->sale->id, $proposal)));
    }
}
