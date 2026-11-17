<?php

namespace Tests\Integration;

use Illuminate\Auth\Access\AuthorizationException;
use Modules\Cash\Contracts\PaymentMethods;
use Modules\Catalog\Contracts\CatalogAdministration;
use Modules\Sales\Application\Services\PreparePayments;
use Modules\Sales\Contracts\AddProduct;
use Modules\Sales\Contracts\OpenSale;
use Modules\Sales\Contracts\SalesOperations;
use Modules\Sales\Domain\SalesConflict;
use Modules\Sales\Domain\SaleType;
use Modules\Users\Contracts\IdentityAccess;
use Modules\Users\Contracts\UserAdministration;
use Tests\Support\FinanceSqlServerTestCase;

class PaymentPreparationTest extends FinanceSqlServerTestCase
{
    private function reject(callable $operation, string $class): void
    {
        try {
            $operation();
            $this->fail('Se esperaba rechazo');
        } catch (\Throwable $e) {
            $this->assertInstanceOf($class, $e);
        }
    }

    public function test_quote_is_authorized_read_only_and_other_requires_existing_administrative_permission(): void
    {
        $catalog = app(CatalogAdministration::class);
        $category = $catalog->createCategory($this->actor, ['name' => 'Quote fixture']);
        $product = $catalog->createProduct($this->actor, ['name' => 'Quote product', 'category_id' => $category->id, 'price' => '100.0000']);
        $sales = app(SalesOperations::class);
        $sale = $sales->open($this->actor, new OpenSale(SaleType::Counter));
        $sale = $sales->addProduct($this->actor, $sale->sale->id, new AddProduct($product->id, 1, null));
        $methods = app(PaymentMethods::class);
        $list = $methods->available($this->actor);
        $quote = app(PreparePayments::class);
        $other = [['payment_method_id' => $list[3]->id, 'applied_amount' => '100', 'reference' => 'OTHER-ABC123']];
        $this->assertSame('100.0000', $quote->quote($this->actor, $sale->sale->id, $other)->applied);
        $users = app(UserAdministration::class);
        $employee = $users->create($this->actor, ['name' => 'Quote cashier', 'username' => 'quote.cashier', 'password' => 'QuoteInitial!123']);
        $users->assignRole($this->actor, $employee->id, 'cashier');
        app(IdentityAccess::class)->changePassword($employee->id, 'QuoteInitial!123', 'QuoteChanged!123');
        $this->reject(fn () => $quote->quote($employee->id, $sale->sale->id, $other), \InvalidArgumentException::class);
        $card = [['payment_method_id' => $list[1]->id, 'applied_amount' => '100', 'reference' => 'CARD-ABC123']];
        $this->assertSame('0.0000', $quote->quote($employee->id, $sale->sale->id, $card)->cashApplied);
        $methods->setActive($this->actor, $list[3]->id, false);
        $this->reject(fn () => $quote->quote($this->actor, $sale->sale->id, $other), \InvalidArgumentException::class);
        $sales->pause($this->actor, $sale->sale->id);
        $this->reject(fn () => $quote->quote($this->actor, $sale->sale->id, $card), SalesConflict::class);
        $this->database->table('role_has_permissions')->where('permission_id', $this->database->table('permissions')->where('name', 'sales.close')->value('id'))->delete();
        $this->reject(fn () => $quote->quote($employee->id, $sale->sale->id, $card), AuthorizationException::class);
        $this->assertSame(0, $this->database->table('sales_payments')->count());
        $this->assertSame(0, $this->database->table('cash_movements')->count());
        $this->assertSame('100.0000', $this->funds()['expected_cash']);
        $this->assertNull($this->database->table('sales_sales')->value('folio'));
    }
}
