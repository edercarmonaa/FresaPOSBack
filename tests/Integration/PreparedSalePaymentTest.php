<?php

namespace Tests\Integration;

use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Modules\Cash\Contracts\PaymentMethods;
use Modules\Catalog\Contracts\CatalogAdministration;
use Modules\Sales\Application\Services\PreparePayments;
use Modules\Sales\Contracts\AddProduct;
use Modules\Sales\Contracts\OpenSale;
use Modules\Sales\Contracts\SalesOperations;
use Modules\Sales\Domain\SaleType;
use Modules\Sales\Infrastructure\Persistence\Models\SalePayment;
use Tests\Support\FinanceSqlServerTestCase;

class PreparedSalePaymentTest extends FinanceSqlServerTestCase
{
    public function test_prepared_mixed_payments_have_real_foreign_keys_and_never_affect_sale_or_cash(): void
    {
        $catalog = app(CatalogAdministration::class);
        $category = $catalog->createCategory($this->actor, ['name' => 'Payment fixture']);
        $product = $catalog->createProduct($this->actor, ['name' => 'Payment product', 'category_id' => $category->id, 'price' => '180.0000']);
        $sales = app(SalesOperations::class);
        $sale = $sales->open($this->actor, new OpenSale(SaleType::Counter));
        $sale = $sales->addProduct($this->actor, $sale->sale->id, new AddProduct($product->id, 1, null));
        $methods = app(PaymentMethods::class);
        $list = $methods->available($this->actor);
        $plan = app(PreparePayments::class)->quote($this->actor, $sale->sale->id, [['payment_method_id' => $list[0]->id, 'applied_amount' => '80', 'received_cash' => '100'], ['payment_method_id' => $list[1]->id, 'applied_amount' => '100', 'reference' => 'CARD-ABC123']]);
        $this->assertSame(0, $this->database->table('sales_payments')->count());
        $rows = $this->database->transaction(function () use ($plan, $sale): array {
            $rows = [];
            foreach ($plan->payments as $p) {
                $rows[] = SalePayment::query()->create(['sale_id' => $sale->sale->id, 'cash_session_id' => $this->sessionId, 'payment_method_id' => $p->paymentMethodId, 'method_code' => $p->methodCode, 'applied_amount' => $p->appliedAmount, 'received_cash' => $p->receivedCash, 'change_cash' => $p->changeCash, 'reference' => $p->reference, 'created_by' => $this->actor, 'status' => 'prepared']);
            }

            return $rows;
        });
        $this->assertCount(2, $rows);
        $this->assertSame('20.0000', $rows[0]->refresh()->getAttribute('change_cash'));
        $this->assertSame('100.0000', $this->funds()['expected_cash']);
        $this->assertSame('0.0000', $this->funds()['confirmed_sales_cash']);
        $this->assertSame('open', $this->database->table('sales_sales')->value('status'));
        $this->assertNull($this->database->table('sales_sales')->value('folio'));
        $this->assertNull($this->database->table('sales_payments')->value('confirmed_at'));
        $methods->setActive($this->actor, $list[0]->id, false);
        $this->assertSame(2, $this->database->table('sales_payments')->count());
        $this->expectException(QueryException::class);
        $this->database->table('cash_payment_methods')->where('id', $list[0]->id)->delete();
    }

    public function test_sql_rejects_confirmed_payments_and_mismatched_methods_without_disabling_constraints(): void
    {
        $sale = app(SalesOperations::class)->open($this->actor, new OpenSale(SaleType::Counter));
        $method = app(PaymentMethods::class)->available($this->actor)[0];
        $base = ['id' => strtolower((string) Str::ulid()), 'sale_id' => $sale->sale->id, 'cash_session_id' => $this->sessionId, 'payment_method_id' => $method->id, 'method_code' => 'cash', 'applied_amount' => '10.0000', 'received_cash' => '20.0000', 'change_cash' => '10.0000', 'created_by' => $this->actor, 'status' => 'prepared', 'created_at' => now()->utc(), 'updated_at' => now()->utc()];
        foreach ([['status' => 'confirmed', 'confirmed_at' => now()->utc()], ['change_cash' => '0.0000'], ['method_code' => 'card'], ['sale_id' => strtolower((string) Str::ulid())], ['cash_session_id' => strtolower((string) Str::ulid())], ['applied_amount' => '-1.0000']] as $changes) {
            try {
                $this->database->table('sales_payments')->insert(array_replace($base, $changes));
                $this->fail('Constraint financiera omitida');
            } catch (QueryException $e) {
                $this->assertContains((int) ($e->errorInfo[1] ?? 0), [547]);
            }
        }
        $this->assertSame(0, $this->database->table('sales_payments')->count());
        $this->assertSame(0, $this->database->table('cash_movements')->count());
        $this->assertSame('100.0000', $this->funds()['expected_cash']);
    }

    public function test_financial_schema_has_exact_types_trusted_constraints_and_no_delete_cascades(): void
    {
        $rows = $this->database->select("SELECT c.name,t.name AS type,c.precision,c.scale,c.max_length FROM sys.columns c JOIN sys.types t ON c.user_type_id=t.user_type_id WHERE c.object_id=OBJECT_ID('cash_movements')");
        $columns = array_column($rows, null, 'name');
        $this->assertSame('nchar', $columns['id']->type);
        $this->assertSame(52, (int) $columns['id']->max_length);
        $this->assertSame('decimal', $columns['amount']->type);
        $this->assertSame(19, (int) $columns['amount']->precision);
        $this->assertSame(4, (int) $columns['amount']->scale);
        $this->assertSame(6, count($this->database->select("SELECT name FROM sys.foreign_keys WHERE parent_object_id IN (OBJECT_ID('cash_movements'),OBJECT_ID('sales_payments')) AND delete_referential_action=0 AND is_disabled=0 AND is_not_trusted=0")));
        $this->assertSame(3, count($this->database->select("SELECT name FROM sys.check_constraints WHERE parent_object_id IN (OBJECT_ID('cash_movements'),OBJECT_ID('cash_payment_methods'),OBJECT_ID('sales_payments')) AND is_disabled=0 AND is_not_trusted=0")));
    }
}
