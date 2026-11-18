<?php

namespace Tests\Support;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Str;
use Modules\Sales\Application\Services\CoordinateSalesWrite;
use Modules\Sales\Application\Services\DurableSalesWrites;
use Modules\Sales\Contracts\CancellationApproval;
use Modules\Sales\Contracts\CriticalSalesWrites;
use Modules\Sales\Contracts\LineRemovalApproval;
use Modules\Sales\Contracts\SaleOperationReasons;
use Modules\Sales\Contracts\SaleSnapshot;
use Modules\Sales\Contracts\SalesOperations;
use Modules\Sales\Infrastructure\AuthorizedSaleReasons;

abstract class ReasonsSqlServerTestCase extends ReconciliationSqlServerTestCase
{
    private ?Migration $reasonSchema = null;

    protected array $removal;

    protected array $cancellation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->reasonSchema = require base_path('Modules/Sales/database/migrations/2026_11_18_000015_create_sale_operation_reasons.php');
        $this->database->transaction(fn () => $this->reasonSchema->up());
        $this->app->bind(CriticalSalesWrites::class, DurableSalesWrites::class);
        $this->app->bind(LineRemovalApproval::class, AuthorizedSaleReasons::class);
        $this->app->bind(CancellationApproval::class, AuthorizedSaleReasons::class);
        $this->removal = app(SaleOperationReasons::class)->write($this->actor, 'create', ['code' => 'QA_REMOVE', 'name' => 'Retiro exclusivo de fixture', 'operation' => 'sale_item_removal'], (string) Str::uuid());
        $this->cancellation = app(SaleOperationReasons::class)->write($this->actor, 'create', ['code' => 'QA_CANCEL', 'name' => 'Cancelación exclusiva de fixture', 'operation' => 'sale_cancellation'], (string) Str::uuid());
    }

    protected function tearDown(): void
    {
        try {
            while ($this->database->transactionLevel() > 0) {
                $this->database->rollBack();
            }
            TestDatabaseGuard::assertConnected($this->database);
            $this->reasonSchema?->down();
        } finally {
            parent::tearDown();
        }
    }

    protected function control(SaleSnapshot $sale, string $action, string $code, ?string $key = null, ?string $itemId = null, ?string $actor = null): SaleSnapshot
    {
        $actor ??= $this->actor;
        $scope = 'sales.'.$sale->sale->id.'.'.$action.($action === 'remove' ? '.'.($itemId ?? $sale->items[0]->id) : '');

        return app(CriticalSalesWrites::class)->run($actor, $scope, $key ?? (string) Str::uuid(), ['reason_code' => $code, 'revision' => $this->revision($sale)],
            fn () => app(CoordinateSalesWrite::class)->run($actor, $sale->sale->id, false, $this->revision($sale),
                fn () => $action === 'remove' ? app(SalesOperations::class)->removeItem($actor, $sale->sale->id, $itemId ?? $sale->items[0]->id, $code)
                    : app(SalesOperations::class)->cancel($actor, $sale->sale->id, $code)));
    }
}
