<?php

namespace Tests\Integration;

use Closure;
use Illuminate\Support\Str;
use Modules\Cash\Contracts\CashClosings;
use Modules\Cash\Contracts\CashMovements;
use Modules\Catalog\Contracts\CatalogAdministration;
use Modules\Catalog\Contracts\ProductQuery;
use Modules\Sales\Contracts\AddProduct;
use Modules\Sales\Contracts\OpenSale;
use Modules\Sales\Contracts\SaleCheckout;
use Modules\Sales\Contracts\SaleQuery;
use Modules\Sales\Contracts\SalesOperations;
use Modules\Sales\Contracts\TableQuery;
use Modules\Sales\Domain\SaleType;
use Tests\Support\ReconciliationSqlServerTestCase;

class FinancialQueryTest extends ReconciliationSqlServerTestCase
{
    private array $measurements = [];

    private function measure(string $label, Closure $operation): mixed
    {
        $this->database->flushQueryLog();
        $this->database->enableQueryLog();
        $start = hrtime(true);
        try {
            return $operation();
        } finally {
            $queries = $this->database->getQueryLog();
            $this->database->disableQueryLog();
            $this->measurements[$label] = ['queries' => count($queries), 'sql_ms' => array_sum(array_column($queries, 'time')), 'wall_ms' => round((hrtime(true) - $start) / 1000000, 3)];
            $this->database->flushQueryLog();
        }
    }

    public function test_paginated_reads_avoid_n_plus_one_and_financial_queries_are_measured(): void
    {
        $catalog = app(CatalogAdministration::class);
        $sales = app(SalesOperations::class);
        $category = $catalog->createCategory($this->actor, ['name' => 'Query fixtures']);
        $created = [];
        for ($index = 0; $index < 26; $index++) {
            $catalog->createProduct($this->actor, ['name' => 'Query product '.$index, 'category_id' => $category->id, 'price' => '1']);
            $table = $sales->createTable($this->actor, 'Query table '.$index);
            $created[] = $sales->open($this->actor, new OpenSale(SaleType::Table, $table->id));
        }
        // Calentamiento de permisos; las medidas no incluyen seeding, hashes ni migraciones.
        $catalog->products($this->actor);
        $sales->tables($this->actor);
        $sales->sales($this->actor);
        foreach ([10, 25] as $size) {
            $products = $this->measure('catalog_'.$size, fn () => $catalog->products($this->actor, new ProductQuery(perPage: $size, availableForSale: true)));
            $tables = $this->measure('tables_'.$size, fn () => $sales->tables($this->actor, new TableQuery(perPage: $size)));
            $page = $this->measure('sales_'.$size, fn () => $sales->sales($this->actor, new SaleQuery(perPage: $size)));
            foreach ([$products, $tables, $page] as $result) {
                $this->assertCount($size, $result->items);
                $this->assertGreaterThan($size, $result->total);
            }
        }
        foreach (['catalog', 'tables', 'sales'] as $label) {
            $this->assertLessThanOrEqual($this->measurements[$label.'_10']['queries'] + 1, $this->measurements[$label.'_25']['queries'], $label.' query count grew with rows');
        }
        foreach ($created as $index => $sale) {
            $sale = $index === 0
                ? $this->measure('add_product', fn () => $sales->addProduct($this->actor, $sale->sale->id, new AddProduct($this->productId, 1)))
                : $sales->addProduct($this->actor, $sale->sale->id, new AddProduct($this->productId, 1));
            $checkout = fn () => app(SaleCheckout::class)->confirm($this->actor, $sale->sale->id, [$this->payment()], (string) Str::uuid(), $this->revision($sale));
            $index === 0 ? $this->measure('checkout_and_folio', $checkout) : $checkout();
        }
        $movement = $this->measure('cash_movement', fn () => app(CashMovements::class)->record($this->actor, $this->sessionId, 'cash_in', '1', 'Query measurement', (string) Str::uuid()));
        $this->assertSame('1.0000', $movement->amount);
        $balance = $this->measure('cash_balance', fn () => app(CashMovements::class)->balance($this->actor));
        $this->assertSame('4781.0000', $balance['expected_cash']);
        $this->measure('cash_close', fn () => $this->closeCount('4781'));
        $this->measure('cash_summary', fn () => app(CashClosings::class)->reconciliation($this->actor, $this->sessionId));
        $history = $this->measure('cash_history', fn () => app(CashClosings::class)->history($this->actor));
        $this->assertSame(1, $history['total']);
        file_put_contents(base_path('.tools/acceptance-query-measurements.json'), json_encode(['database' => 'fresapos_test', 'fixture_sales' => 26, 'measurements' => $this->measurements, 'scope' => 'Local smoke measurement, not a load-test SLA'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
}
