<?php

namespace Tests\Integration;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Cash\Application\Services\ProvisionCashRegister;
use Modules\Cash\Contracts\ActiveCashSession;
use Modules\Cash\Contracts\CashConflict;
use Modules\Cash\Contracts\CashOperations;
use Modules\Cash\Contracts\CashSessionUnavailable;
use Modules\Cash\Contracts\SessionSnapshot;
use Modules\Cash\Infrastructure\Models\CashRegister;
use Modules\Cash\Infrastructure\Models\CashSession;
use Modules\Cash\Infrastructure\Seeders\CashRegisterSeeder;
use Modules\Catalog\Contracts\CatalogAdministration;
use Modules\Core\Contracts\SecurityEvent;
use Modules\Core\Contracts\TransactionManager;
use Modules\Sales\Contracts\AddProduct;
use Modules\Sales\Contracts\CriticalSalesWrites;
use Modules\Sales\Contracts\OpenSale;
use Modules\Sales\Contracts\SalesOperations;
use Modules\Sales\Domain\SalesConflict;
use Modules\Sales\Domain\SalesDependencyUnavailable;
use Modules\Sales\Domain\SaleType;
use Modules\Users\Application\BootstrapAdministrator;
use Modules\Users\Contracts\IdentityAccess;
use Modules\Users\Contracts\UserAdministration;
use Tests\Support\CashSqlServerTestCase;

class CashTest extends CashSqlServerTestCase
{
    protected string $actor;

    protected string $register;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = app(BootstrapAdministrator::class)->create('Cash fixture', 'cash.admin', 'CashInitial!123');
        app(IdentityAccess::class)->changePassword($this->actor, 'CashInitial!123', 'CashChanged!123');
        $this->register = app(ProvisionCashRegister::class)->run()->id;
    }

    protected function open(string $fund = '100.0000', ?string $key = null): SessionSnapshot
    {
        return app(CashOperations::class)->open($this->actor, $this->register, $fund, $key ?? strtolower((string) Str::ulid()));
    }

    protected function rejects(callable $operation, string $class): void
    {
        try {
            $operation();
            $this->fail('Se esperaba rechazo: '.$class);
        } catch (\Throwable $exception) {
            $this->assertInstanceOf($class, $exception);
        }
    }

    protected function employee(string $role): string
    {
        $users = app(UserAdministration::class);
        $id = $users->create($this->actor, ['name' => 'Cash employee', 'username' => 'cash.'.$role, 'password' => 'EmployeeInitial!123'])->id;
        $users->assignRole($this->actor, $id, $role);
        app(IdentityAccess::class)->changePassword($id, 'EmployeeInitial!123', 'EmployeeChanged!123');

        return $id;
    }

    public function test_provisioning_and_seeder_are_idempotent_and_do_not_reactivate_or_open(): void
    {
        app(CashRegisterSeeder::class)->run();
        $this->assertSame($this->register, app(ProvisionCashRegister::class)->run()->id);
        $this->assertTrue(Str::isUlid($this->register));
        $this->assertTrue(app(CashOperations::class)->registers($this->actor)[0]->isActive);
        app(CashOperations::class)->setRegisterActive($this->actor, $this->register, false);
        app(CashRegisterSeeder::class)->run();
        $this->assertFalse(app(ProvisionCashRegister::class)->run()->isActive);
        $this->assertSame(1, $this->database->table('cash_registers')->count());
        $this->assertSame(0, $this->database->table('cash_sessions')->count());
    }

    public function test_open_zero_and_query_preserve_ulid_actor_utc_and_no_financial_side_effects(): void
    {
        $this->assertNull(app(CashOperations::class)->current($this->actor));
        $session = $this->open('0');
        $this->assertTrue(Str::isUlid($session->id));
        $this->assertSame('0.0000', $session->openingFund);
        $this->assertSame($this->actor, $session->openedBy);
        $this->assertSame($this->register, $session->register->id);
        $this->assertSame('open', $session->status);
        $this->assertStringEndsWith('Z', $session->openedAt);
        $this->assertNull($session->closedAt);
        $this->assertNull($session->closedBy);
        $this->assertEquals($session, app(CashOperations::class)->current($this->actor));
        $event = $this->database->table('audit_security_events')->where('event_type', 'cash.session.opened')->sole();
        $this->assertSame($session->id, $event->record_id);
        $this->assertSame($this->actor, $event->actor_id);
        $this->assertNull($event->change_data);
        $this->assertSame(0, $this->database->table('sales_sales')->count());
    }

    public function test_durable_replay_normalizes_fund_and_conflicts_on_new_payload_or_key(): void
    {
        $key = strtolower((string) Str::ulid());
        $session = $this->open('123.45', $key);
        // Resuelto desde otra instancia del servicio y fila SQL, no memoria/cache.
        $this->assertEquals($session, $this->open('123.4500', $key));
        $this->rejects(fn () => $this->open('124', $key), CashConflict::class);
        $this->rejects(fn () => $this->open('123.45'), CashConflict::class);
        $this->assertSame(1, $this->database->table('cash_sessions')->count());
        $this->assertSame(1, $this->database->table('audit_security_events')->where('event_type', 'cash.session.opened')->count());
        $this->assertSame(2, $this->database->table('audit_security_events')->where('event_type', 'cash.open.rejected')->count());
        $row = $this->database->table('cash_sessions')->sole();
        $this->assertSame(hash('sha256', $key), $row->opening_key_hash);
        $this->assertStringNotContainsString($key, json_encode($this->database->table('audit_security_events')->get()));
    }

    public function test_replay_after_fixture_closed_never_reopens_and_next_key_preserves_history(): void
    {
        $key = strtolower((string) Str::ulid());
        $session = $this->open('50', $key);
        // Solo fixture: no implementa un caso de uso productivo de cierre.
        $this->database->table('cash_sessions')->where('id', $session->id)->update(['status' => 'closed', 'closed_at' => now()->utc()->addSecond(), 'closed_by' => $this->actor]);
        $this->assertNull(app(CashOperations::class)->current($this->actor));
        $this->assertSame('closed', $this->open('50', $key)->status);
        $next = $this->open('25');
        $this->assertNotSame($session->id, $next->id);
        $this->assertSame(2, $this->database->table('cash_sessions')->count());
        $this->assertSame(1, $this->database->table('cash_sessions')->where('status', 'open')->count());
    }

    public function test_missing_inactive_register_and_deactivation_with_session_are_rejected(): void
    {
        $cash = app(CashOperations::class);
        $this->rejects(fn () => $cash->open($this->actor, strtolower((string) Str::ulid()), '0', 'cash-open-key-123456'), ModelNotFoundException::class);
        $cash->setRegisterActive($this->actor, $this->register, false);
        $this->rejects(fn () => $this->open(), CashConflict::class);
        $cash->setRegisterActive($this->actor, $this->register, true);
        $this->open();
        $this->rejects(fn () => $cash->setRegisterActive($this->actor, $this->register, false), CashConflict::class);
        $this->assertTrue($cash->registers($this->actor)[0]->isActive);
    }

    public function test_permissions_are_fresh_and_waiter_cannot_read_funds_or_open(): void
    {
        $waiter = $this->employee('waiter');
        $cashier = $this->employee('cashier');
        $cash = app(CashOperations::class);
        $this->rejects(fn () => $cash->current($waiter), AuthorizationException::class);
        $this->rejects(fn () => $cash->open($waiter, $this->register, '0', 'cash-open-key-123456'), AuthorizationException::class);
        $session = $cash->open($cashier, $this->register, '5', 'cash-open-key-123456');
        $this->assertSame($cashier, $session->openedBy);
        app(UserAdministration::class)->setRolePermissions($this->actor, 'cashier', ['cash.view']);
        $this->rejects(fn () => $cash->open($cashier, $this->register, '5', 'cash-open-key-123456'), AuthorizationException::class);
        app(UserAdministration::class)->setActive($this->actor, $cashier, false);
        $this->rejects(fn () => $cash->current($cashier), AuthorizationException::class);
        $this->assertSame(1, $this->database->table('cash_sessions')->count());
    }

    public function test_audit_failure_rolls_back_session_and_allows_retry_with_same_key(): void
    {
        $fail = true;
        Event::listen(SecurityEvent::class, function (SecurityEvent $event) use (&$fail): void {
            if ($fail && $event->type === 'cash.session.opened') {
                throw new \RuntimeException('Fixture audit failure');
            }
        });
        $key = 'cash-audit-retry-123456';
        $this->rejects(fn () => $this->open('10', $key), \RuntimeException::class);
        $this->assertSame(0, $this->database->table('cash_sessions')->count());
        $this->assertSame(0, $this->database->table('audit_security_events')->where('event_type', 'cash.session.opened')->count());
        $fail = false;
        $this->assertSame('10.0000', $this->open('10', $key)->openingFund);
    }

    public function test_real_adapter_requires_external_transaction_original_session_and_active_actor(): void
    {
        $adapter = app(ActiveCashSession::class);
        $this->rejects(fn () => $adapter->requireActive($this->actor), \LogicException::class);
        $tx = app(TransactionManager::class);
        $this->rejects(fn () => $tx->run(fn () => $adapter->requireActive($this->actor)), CashSessionUnavailable::class);
        $session = $this->open();
        $this->assertSame($session->id, $tx->run(fn () => $adapter->requireActive($this->actor, $session->id)));
        $this->rejects(fn () => $tx->run(fn () => $adapter->requireActive($this->actor, strtolower((string) Str::ulid()))), CashSessionUnavailable::class);
        $this->rejects(fn () => $tx->run(fn () => $adapter->requireActive(strtolower((string) Str::ulid()))), AuthorizationException::class);
    }

    public function test_sales_domain_uses_real_session_and_keeps_history_rules_while_public_critical_writes_remain_blocked(): void
    {
        $sales = app(SalesOperations::class);
        $this->rejects(fn () => $sales->open($this->actor, new OpenSale(SaleType::Counter)), CashSessionUnavailable::class);
        $session = $this->open();
        $waiter = $this->employee('waiter');
        $counter = $sales->open($waiter, new OpenSale(SaleType::Counter));
        $table = $sales->createTable($this->actor, 'Mesa Cash');
        $atTable = $sales->open($waiter, new OpenSale(SaleType::Table, $table->id));
        $this->rejects(fn () => $sales->open($waiter, new OpenSale(SaleType::Table, $table->id)), SalesConflict::class);
        foreach ([$counter, $atTable] as $sale) {
            $this->assertSame($session->id, $sale->sale->cashSessionId);
            $this->assertSame($waiter, $sale->sale->createdBy);
            $this->assertNull($sale->sale->folio);
            $this->assertNull($sale->sale->closedAt);
            $this->assertSame('0.0000', $sale->sale->total);
        }
        $catalog = app(CatalogAdministration::class);
        $category = $catalog->createCategory($this->actor, ['name' => 'Cash category']);
        $product = $catalog->createProduct($this->actor, ['name' => 'Cash product', 'category_id' => $category->id, 'price' => '12.1234']);
        $sale = $sales->addProduct($waiter, $counter->sale->id, new AddProduct($product->id, 2));
        $catalog->updateProduct($this->actor, $product->id, ['price' => '20.0000']);
        $sale = $sales->changeQuantity($waiter, $sale->sale->id, $sale->items[0]->id, 1);
        $this->assertSame('12.1234', $sale->sale->total);
        $sales->editNote($waiter, $sale->sale->id, 'Nota Cash');
        $sales->pause($waiter, $sale->sale->id);
        $sales->resume($waiter, $sale->sale->id);
        $this->rejects(fn () => app(CriticalSalesWrites::class)->run($waiter, 'sales.open', 'cash-open-key-123456', [], fn () => throw new \LogicException('No ejecutar')), SalesDependencyUnavailable::class);
        $this->assertSame(2, $this->database->table('sales_sales')->count());
        $this->assertSame(0, $this->database->table('sales_sales')->whereNotNull('folio')->count());
    }

    public function test_schema_types_constraints_and_real_sales_foreign_key(): void
    {
        $columns = $this->database->select("SELECT c.name,t.name AS type,c.max_length,c.precision,c.scale FROM sys.columns c JOIN sys.types t ON c.user_type_id=t.user_type_id WHERE c.object_id=OBJECT_ID('cash_sessions')");
        $columns = array_column($columns, null, 'name');
        $this->assertSame('nchar', $columns['id']->type);
        $this->assertSame(52, (int) $columns['id']->max_length);
        $this->assertSame('decimal', $columns['opening_fund']->type);
        $this->assertSame(19, (int) $columns['opening_fund']->precision);
        $this->assertSame(4, (int) $columns['opening_fund']->scale);
        $this->assertSame(4, count($this->database->select("SELECT name FROM sys.foreign_keys WHERE parent_object_id IN (OBJECT_ID('cash_sessions'),OBJECT_ID('sales_sales')) AND (name='sales_cash_session_foreign' OR parent_object_id=OBJECT_ID('cash_sessions')) AND delete_referential_action=0")));
        $session = $this->open('999999999999999.9999');
        $this->assertSame('999999999999999.9999', app(CashOperations::class)->current($this->actor)->openingFund);
        $this->rejects(fn () => CashSession::query()->findOrFail($session->id)->delete(), \LogicException::class);
        $this->rejects(fn () => CashRegister::query()->findOrFail($this->register)->delete(), \LogicException::class);
        $this->rejects(fn () => $this->database->table('cash_registers')->delete(), QueryException::class);
        $this->rejects(fn () => $this->database->table('cash_sessions')->where('id', $session->id)->update(['opening_fund' => '-0.0001']), QueryException::class);
        $this->rejects(fn () => $this->database->table('cash_sessions')->where('id', $session->id)->update(['status' => 'closed']), QueryException::class);
        $this->rejects(fn () => $this->database->table('sales_sales')->insert(['id' => strtolower((string) Str::ulid()), 'type' => 'counter', 'status' => 'open', 'created_by' => $this->actor, 'cash_session_id' => strtolower((string) Str::ulid())]), QueryException::class);
        $this->assertSame(0, $this->database->table('sales_sales')->count());
        $this->rejects(fn () => $this->database->table('cash_registers')->insert(['id' => strtolower((string) Str::ulid()), 'slot' => 2, 'name' => 'No admitida', 'is_active' => true]), QueryException::class);
        $this->rejects(fn () => $this->database->table('cash_registers')->insert(['id' => strtolower((string) Str::ulid()), 'slot' => 1, 'name' => 'Duplicada', 'is_active' => true]), QueryException::class);
    }

    public function test_sales_link_migration_rejects_orphans_without_rewriting_history(): void
    {
        $migration = require base_path('Modules/Sales/database/migrations/2026_11_17_000008_link_sales_to_cash_sessions.php');
        $migration->down();
        $id = strtolower((string) Str::ulid());
        $unknownSession = strtolower((string) Str::ulid());
        $this->database->table('sales_sales')->insert(['id' => $id, 'type' => 'counter', 'status' => 'open', 'created_by' => $this->actor, 'cash_session_id' => $unknownSession]);
        $this->rejects(fn () => $migration->up(), \RuntimeException::class);
        $this->assertSame($unknownSession, $this->database->table('sales_sales')->where('id', $id)->value('cash_session_id'));
        // Retirar únicamente la fila de fixture para dejar instalada la FK del resto del caso.
        $this->database->table('sales_sales')->where('id', $id)->delete();
        $migration->up();
        $this->assertSame(1, count($this->database->select("SELECT name FROM sys.foreign_keys WHERE name='sales_cash_session_foreign' AND is_not_trusted=0")));
    }
}
