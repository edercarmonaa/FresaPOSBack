<?php

namespace Tests\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Modules\Cash\Contracts\PaymentMethods;
use Modules\Catalog\Contracts\CatalogAdministration;
use Modules\Sales\Application\SalesRevision;
use Modules\Sales\Contracts\AddProduct;
use Modules\Sales\Contracts\DiscountApproval;
use Modules\Sales\Contracts\OpenSale;
use Modules\Sales\Contracts\SaleSnapshot;
use Modules\Sales\Contracts\SalesOperations;
use Modules\Sales\Domain\SaleType;
use Modules\Sales\Infrastructure\UnavailableDiscountApproval;

abstract class CheckoutSqlServerTestCase extends FinanceSqlServerTestCase
{
    private ?Migration $checkoutSchema = null;

    protected string $productId;

    protected array $methodIds;

    protected function setUp(): void
    {
        // Reloj determinista anterior a los workers de medianoche/transición.
        CarbonImmutable::setTestNow('2026-11-17T18:00:00Z');
        parent::setUp();
        // Suite histórica sin 000016: el aprobador ausente es parte del fixture.
        $this->app->bind(DiscountApproval::class, UnavailableDiscountApproval::class);
        $this->checkoutSchema = require base_path('Modules/Sales/database/migrations/2026_11_17_000012_enable_sale_checkout.php');
        $this->database->transaction(fn () => $this->checkoutSchema->up());
        $catalog = app(CatalogAdministration::class);
        $category = $catalog->createCategory($this->actor, ['name' => 'Checkout fixture']);
        $this->productId = $catalog->createProduct($this->actor, ['name' => 'Checkout product', 'category_id' => $category->id, 'price' => '180.0000'])->id;
        $this->methodIds = array_column(app(PaymentMethods::class)->available($this->actor), 'id', 'code');
        config(['pos.business_timezone' => 'America/Mexico_City']);
    }

    protected function tearDown(): void
    {
        try {
            CarbonImmutable::setTestNow();
            Carbon::setTestNow();
            while ($this->database->transactionLevel() > 0) {
                $this->database->rollBack();
            }
            TestDatabaseGuard::assertConnected($this->database);
            $this->checkoutSchema?->down();
        } finally {
            parent::tearDown();
        }
    }

    protected function sale(bool $table = false): SaleSnapshot
    {
        $sales = app(SalesOperations::class);
        $id = $table ? $sales->createTable($this->actor, 'Checkout table')->id : null;
        $sale = $sales->open($this->actor, new OpenSale($table ? SaleType::Table : SaleType::Counter, $id));

        return $sales->addProduct($this->actor, $sale->sale->id, new AddProduct($this->productId, 1, 'Historical note'));
    }

    protected function payment(string $code = 'cash', string $amount = '180', ?string $received = '200'): array
    {
        return ['payment_method_id' => $this->methodIds[$code], 'applied_amount' => $amount,
            ...($code === 'cash' ? ['received_cash' => $received] : []),
            ...(in_array($code, ['transfer', 'other'], true) ? ['reference' => 'RECEIPT-ABC'] : [])];
    }

    protected function revision(SaleSnapshot $sale): string
    {
        return '"'.SalesRevision::of($sale).'"';
    }
}
