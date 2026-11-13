<?php

namespace Tests\Integration;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Cash\Contracts\ActiveCashSession;
use Modules\Catalog\Contracts\CatalogAdministration;
use Modules\Catalog\Domain\CatalogConflict;
use Modules\Core\Contracts\SecurityEvent;
use Modules\Core\Contracts\TransactionManager;
use Modules\Sales\Application\Ports\SalesStore;
use Modules\Sales\Contracts\AddProduct;
use Modules\Sales\Contracts\Events\SaleChanged;
use Modules\Sales\Contracts\LineRemovalApproval;
use Modules\Sales\Contracts\OpenSale;
use Modules\Sales\Contracts\QuantityLimits;
use Modules\Sales\Contracts\SaleQuery;
use Modules\Sales\Contracts\SaleSnapshot;
use Modules\Sales\Contracts\SalesOperations;
use Modules\Sales\Contracts\TableQuery;
use Modules\Sales\Domain\Amount;
use Modules\Sales\Domain\SalesConflict;
use Modules\Sales\Domain\SaleState;
use Modules\Sales\Domain\SaleType;
use Modules\Sales\Infrastructure\Models\DiningTable;
use Modules\Sales\Infrastructure\Models\Sale;
use Modules\Sales\Infrastructure\Models\SaleItem;
use Modules\Sales\Infrastructure\UnavailableCashSession;
use Modules\Users\Application\BootstrapAdministrator;
use Modules\Users\Contracts\IdentityAccess;
use Modules\Users\Contracts\UserAdministration;
use RuntimeException;
use Tests\Fixtures\Sales\TestCashSession;
use Tests\Fixtures\Sales\TestRemovalApproval;
use Tests\Support\SalesSqlServerTestCase;
use Throwable;

class SalesTest extends SalesSqlServerTestCase
{
    private string $admin;

    private string $category;

    private string $product;

    private TestCashSession $cash;

    private SalesOperations $sales;

    private CatalogAdministration $catalog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = app(BootstrapAdministrator::class)->create('Fixture Sales', 'sales.admin', 'SalesInitial!123');
        app(IdentityAccess::class)->changePassword($this->admin, 'SalesInitial!123', 'SalesChanged!123');
        $this->cash = new TestCashSession(strtolower((string) Str::ulid()));
        $this->app->instance(ActiveCashSession::class, $this->cash);
        $this->sales = app(SalesOperations::class);
        $this->catalog = app(CatalogAdministration::class);
        $this->category = $this->catalog->createCategory($this->admin, ['name' => 'Bebidas'])->id;
        $this->product = $this->catalog->createProduct($this->admin, ['name' => 'Fresa', 'category_id' => $this->category, 'price' => '10.1234'])->id;
    }

    private function open(): SaleSnapshot
    {
        return $this->sales->open($this->admin, new OpenSale(SaleType::Counter));
    }

    private function rejects(Closure $operation, string $exception): void
    {
        try {
            $operation();
        } catch (Throwable $caught) {
            $this->assertInstanceOf($exception, $caught, $caught->getMessage());

            return;
        }
        $this->fail('La operación debió rechazarse: '.$exception);
    }

    private function employee(string $role): string
    {
        $users = app(UserAdministration::class);
        $id = $users->create($this->admin, ['name' => 'Fixture '.$role, 'username' => 'sales.'.$role, 'password' => 'EmployeeInitial!123'])->id;
        $users->assignRole($this->admin, $id, $role);
        app(IdentityAccess::class)->changePassword($id, 'EmployeeInitial!123', 'EmployeeChanged!123');

        return $id;
    }

    public function test_open_counter_persists_ulid_actor_cash_and_zero_totals_without_folio(): void
    {
        $sale = $this->sales->open($this->admin, new OpenSale(SaleType::Counter, note: '  Nota   General  '));
        $this->assertTrue(Str::isUlid($sale->sale->id));
        $this->assertSame(SaleState::Open, $sale->sale->state);
        $this->assertSame($this->admin, $sale->sale->createdBy);
        $this->assertSame($this->cash->id, $sale->sale->cashSessionId);
        $this->assertNull($sale->sale->tableId);
        $this->assertNull($sale->sale->folio);
        $this->assertNull($sale->sale->closedAt);
        $this->assertSame('Nota General', $sale->sale->note);
        $this->assertSame(['0.0000', '0.0000', '0.0000'], [$sale->sale->subtotal, $sale->sale->discount, $sale->sale->total]);
        $this->assertSame($sale->sale->id, $this->sales->sale($this->admin, $sale->sale->id)->sale->id);
    }

    public function test_missing_cash_default_adapter_closed_cash_and_changed_session_never_open_or_edit(): void
    {
        $this->app->instance(ActiveCashSession::class, new UnavailableCashSession);
        $this->rejects(fn () => app(SalesOperations::class)->open($this->admin, new OpenSale(SaleType::Counter)), SalesConflict::class);
        $this->assertSame(0, $this->database->table('sales_sales')->count());
        $sale = $this->open();
        $this->cash->active = false;
        $this->rejects(fn () => $this->open(), SalesConflict::class);
        $this->rejects(fn () => $this->sales->addProduct($this->admin, $sale->sale->id, new AddProduct($this->product, 1)), SalesConflict::class);
        $this->app->instance(ActiveCashSession::class, new TestCashSession(strtolower((string) Str::ulid())));
        $this->rejects(fn () => app(SalesOperations::class)->editNote($this->admin, $sale->sale->id, 'Cambio'), SalesConflict::class);
        $this->assertSame(0, $this->database->table('sales_items')->count());
    }

    public function test_table_types_active_occupancy_and_cancel_release_are_enforced(): void
    {
        $table = $this->sales->createTable($this->admin, '  Mesa   Ázul  ', 7);
        $this->assertSame('Mesa Ázul', $table->name);
        $this->assertFalse($table->isOccupied);
        $this->rejects(fn () => $this->sales->createTable($this->admin, 'MESA AZUL'), SalesConflict::class);
        $this->rejects(fn () => $this->sales->open($this->admin, new OpenSale(SaleType::Table)), SalesConflict::class);
        $this->rejects(fn () => $this->sales->open($this->admin, new OpenSale(SaleType::Counter, $table->id)), SalesConflict::class);
        $sale = $this->sales->open($this->admin, new OpenSale(SaleType::Table, $table->id));
        $this->assertSame($table->id, $sale->sale->tableId);
        $this->assertTrue($this->sales->table($this->admin, $table->id)->isOccupied);
        $this->assertSame(1, $this->sales->tables($this->admin, new TableQuery(isOccupied: true))->total);
        $this->rejects(fn () => $this->sales->open($this->admin, new OpenSale(SaleType::Table, $table->id)), SalesConflict::class);
        $this->rejects(fn () => $this->sales->pause($this->admin, $sale->sale->id), SalesConflict::class);
        $cancelled = $this->sales->cancel($this->admin, $sale->sale->id, 'Error de apertura');
        $this->assertSame(SaleState::Cancelled, $cancelled->sale->state);
        $this->assertFalse($this->sales->table($this->admin, $table->id)->isOccupied);
        $this->sales->updateTable($this->admin, $table->id, $table->name, false);
        $this->rejects(fn () => $this->sales->open($this->admin, new OpenSale(SaleType::Table, $table->id)), SalesConflict::class);
        $this->assertSame(0, $this->sales->tables($this->admin, new TableQuery(isActive: true, isOccupied: false))->total);
    }

    public function test_tables_sort_alphabetically_search_literals_and_paginate_on_server_without_n_plus_one(): void
    {
        foreach (['Zulu', 'Álpha', 'Beta', 'Mesa %'] as $i => $name) {
            $this->sales->createTable($this->admin, $name, 10 - $i);
        }
        $this->database->enableQueryLog();
        $page = $this->sales->tables($this->admin);
        $queries = $this->database->getQueryLog();
        $this->database->disableQueryLog();
        $this->assertSame(['Álpha', 'Beta', 'Mesa %', 'Zulu'], array_column($page->items, 'name'));
        $this->assertSame(4, $page->total);
        $this->assertCount(2, array_filter($queries, fn ($query) => str_contains($query['query'], 'sales_tables')));
        $this->assertSame(1, $this->sales->tables($this->admin, new TableQuery(search: '%'))->total);
        for ($i = 0; $i < 8; $i++) {
            $this->sales->createTable($this->admin, 'Extra '.$i);
        }
        $this->assertCount(2, $this->sales->tables($this->admin, new TableQuery(page: 2, perPage: 10))->items);
    }

    public function test_adding_captures_history_and_changes_in_catalog_create_distinct_new_snapshots(): void
    {
        $sale = $this->open();
        $first = $this->sales->addProduct($this->admin, $sale->sale->id, new AddProduct($this->product, 2));
        $this->assertSame('20.2468', $first->sale->total);
        $this->catalog->updateProduct($this->admin, $this->product, ['name' => 'Fresa Nueva', 'price' => '1.0001']);
        $this->catalog->updateCategory($this->admin, $this->category, ['name' => 'Nueva categoría']);
        $second = $this->sales->addProduct($this->admin, $sale->sale->id, new AddProduct($this->product, 1));
        $this->assertCount(2, $second->items);
        $this->assertSame(['Fresa', 'Bebidas', '10.1234', 2], [$second->items[0]->name, $second->items[0]->categoryName, $second->items[0]->unitPrice, $second->items[0]->quantity]);
        $this->assertSame(['Fresa Nueva', 'Nueva categoría', '1.0001'], [$second->items[1]->name, $second->items[1]->categoryName, $second->items[1]->unitPrice]);
        $newCategory = $this->catalog->createCategory($this->admin, ['name' => 'Otra categoría'])->id;
        $this->catalog->updateProduct($this->admin, $this->product, ['category_id' => $newCategory]);
        $third = $this->sales->addProduct($this->admin, $sale->sale->id, new AddProduct($this->product, 1));
        $this->assertCount(3, $third->items);
        $this->assertSame($this->category, $third->items[0]->categoryId);
        $this->assertSame($newCategory, $third->items[2]->categoryId);
        $this->catalog->deleteProduct($this->admin, $this->product);
        $this->rejects(fn () => $this->sales->addProduct($this->admin, $sale->sale->id, new AddProduct($this->product, 1)), CatalogConflict::class);
        $this->rejects(fn () => $this->sales->changeQuantity($this->admin, $sale->sale->id, $third->items[0]->id, 3), CatalogConflict::class);
        $historical = $this->sales->changeQuantity($this->admin, $sale->sale->id, $third->items[0]->id, 1);
        $this->assertSame('10.1234', $historical->items[0]->lineTotal);
        $this->assertCount(3, $historical->items);
    }

    public function test_inactive_product_or_category_blocks_new_units_but_keeps_prior_lines(): void
    {
        $sale = $this->open();
        $this->sales->addProduct($this->admin, $sale->sale->id, new AddProduct($this->product, 1));
        $this->catalog->setProductActive($this->admin, $this->product, false);
        $this->rejects(fn () => $this->sales->addProduct($this->admin, $sale->sale->id, new AddProduct($this->product, 1)), CatalogConflict::class);
        $this->catalog->setProductActive($this->admin, $this->product, true);
        $this->catalog->setCategoryActive($this->admin, $this->category, false);
        $this->rejects(fn () => $this->sales->addProduct($this->admin, $sale->sale->id, new AddProduct($this->product, 1)), CatalogConflict::class);
        $this->assertCount(1, $this->sales->sale($this->admin, $sale->sale->id)->items);
    }

    public function test_quantity_increase_requires_current_availability_and_matching_historical_snapshot(): void
    {
        $sale = $this->open();
        $line = $this->sales->addProduct($this->admin, $sale->sale->id, new AddProduct($this->product, 1))->items[0];
        $increased = $this->sales->changeQuantity($this->admin, $sale->sale->id, $line->id, 2);
        $this->assertSame(2, $increased->items[0]->quantity);
        $this->catalog->changePrice($this->admin, $this->product, '20.0000');
        $this->rejects(fn () => $this->sales->changeQuantity($this->admin, $sale->sale->id, $line->id, 3), SalesConflict::class);
        $newUnits = $this->sales->addProduct($this->admin, $sale->sale->id, new AddProduct($this->product, 1));
        $this->assertSame([2, 1], array_column($newUnits->items, 'quantity'));
        $this->assertSame(['10.1234', '20.0000'], array_column($newUnits->items, 'unitPrice'));
        $this->catalog->setProductActive($this->admin, $this->product, false);
        $this->rejects(fn () => $this->sales->changeQuantity($this->admin, $sale->sale->id, $newUnits->items[1]->id, 2), CatalogConflict::class);
        $this->sales->changeQuantity($this->admin, $sale->sale->id, $line->id, 1);
        $this->assertSame('30.1234', $this->sales->sale($this->admin, $sale->sale->id)->sale->total);
    }

    public function test_equivalent_lines_consolidate_notes_preserve_case_and_insertion_order(): void
    {
        $sale = $this->open();
        $first = $this->sales->addProduct($this->admin, $sale->sale->id, new AddProduct($this->product, 1, '  Sin   Cebolla  '));
        $next = $this->sales->addProduct($this->admin, $sale->sale->id, new AddProduct($this->product, 2, 'Sin Cebolla'));
        $this->assertCount(1, $next->items);
        $this->assertSame($first->items[0]->id, $next->items[0]->id);
        $this->assertSame(3, $next->items[0]->quantity);
        $this->sales->addProduct($this->admin, $sale->sale->id, new AddProduct($this->product, 1, 'Sin cebolla'));
        $this->sales->addProduct($this->admin, $sale->sale->id, new AddProduct($this->product, 1));
        $final = $this->sales->addProduct($this->admin, $sale->sale->id, new AddProduct($this->product, 1, '  '));
        $this->assertSame([1, 2, 3], array_column($final->items, 'position'));
        $this->assertSame(['Sin Cebolla', 'Sin cebolla', null], array_column($final->items, 'note'));
        $this->assertSame([3, 1, 2], array_column($final->items, 'quantity'));
    }

    public function test_quantity_limits_apply_to_new_and_consolidated_lines_without_partial_changes(): void
    {
        $sale = $this->open();
        foreach ([0, -1, 101, 1.5, '1', true] as $quantity) {
            $this->rejects(fn () => $this->sales->addProduct($this->admin, $sale->sale->id, new AddProduct($this->product, $quantity)), ValidationException::class);
        }
        $full = $this->sales->addProduct($this->admin, $sale->sale->id, new AddProduct($this->product, 100));
        $this->rejects(fn () => $this->sales->addProduct($this->admin, $sale->sale->id, new AddProduct($this->product, 1)), ValidationException::class);
        $this->rejects(fn () => $this->sales->changeQuantity($this->admin, $sale->sale->id, $full->items[0]->id, 101), ValidationException::class);
        $decreased = $this->sales->changeQuantity($this->admin, $sale->sale->id, $full->items[0]->id, 1);
        $this->assertSame('10.1234', $decreased->sale->total);
        $this->assertCount(1, $decreased->items);
    }

    public function test_product_specific_quantity_limit_can_be_supplied_without_a_product_schema_change(): void
    {
        $this->app->instance(QuantityLimits::class, new class implements QuantityLimits
        {
            public function maximumFor(string $productId): int
            {
                return 2;
            }
        });
        $sales = app(SalesOperations::class);
        $sale = $sales->open($this->admin, new OpenSale(SaleType::Counter));
        $sales->addProduct($this->admin, $sale->sale->id, new AddProduct($this->product, 2));
        $this->rejects(fn () => $sales->addProduct($this->admin, $sale->sale->id, new AddProduct($this->product, 1)), ValidationException::class);
    }

    public function test_item_and_sale_notes_enforce_limits_are_plain_text_and_not_logged(): void
    {
        $sale = $this->open();
        $line = $this->sales->addProduct($this->admin, $sale->sale->id, new AddProduct($this->product, 1, str_repeat('n', 100)))->items[0];
        $this->sales->editNote($this->admin, $sale->sale->id, str_repeat('N', 150));
        $changed = $this->sales->editItemNote($this->admin, $sale->sale->id, $line->id, ' Nota   Sensible ');
        $this->assertSame('Nota Sensible', $changed->items[0]->note);
        $this->rejects(fn () => $this->sales->editItemNote($this->admin, $sale->sale->id, $line->id, str_repeat('n', 101)), ValidationException::class);
        $this->rejects(fn () => $this->sales->editNote($this->admin, $sale->sale->id, str_repeat('N', 151)), ValidationException::class);
        $this->rejects(fn () => $this->sales->editNote($this->admin, $sale->sale->id, '<b>HTML</b>'), ValidationException::class);
        $this->assertStringNotContainsString('Nota Sensible', $this->database->table('audit_security_events')->get()->toJson());
        $events = $this->database->table('audit_security_events')->count();
        $this->sales->editItemNote($this->admin, $sale->sale->id, $line->id, 'Nota Sensible');
        $this->assertSame($events, $this->database->table('audit_security_events')->count());
    }

    public function test_pause_resume_preserves_history_and_disallows_editing_paused_sales(): void
    {
        $sale = $this->open();
        $full = $this->sales->addProduct($this->admin, $sale->sale->id, new AddProduct($this->product, 2, 'Sin hielo'));
        $this->sales->editNote($this->admin, $sale->sale->id, 'Entrega después');
        $paused = $this->sales->pause($this->admin, $sale->sale->id);
        $this->assertSame(SaleState::Paused, $paused->sale->state);
        $this->assertEquals($full->items, $paused->items);
        $this->assertSame('20.2468', $paused->sale->total);
        $this->assertSame('Entrega después', $paused->sale->note);
        $this->assertNull($paused->sale->folio);
        $this->rejects(fn () => $this->sales->editNote($this->admin, $sale->sale->id, 'Cambio'), SalesConflict::class);
        $resumed = $this->sales->resume($this->admin, $sale->sale->id);
        $this->assertSame(SaleState::Open, $resumed->sale->state);
        $this->assertSame($this->admin, $resumed->sale->createdBy);
        $this->rejects(fn () => $this->sales->resume($this->admin, $sale->sale->id), SalesConflict::class);
    }

    public function test_cancellation_requires_reason_retains_data_and_is_terminal(): void
    {
        $sale = $this->open();
        $line = $this->sales->addProduct($this->admin, $sale->sale->id, new AddProduct($this->product, 1));
        $this->rejects(fn () => $this->sales->cancel($this->admin, $sale->sale->id, '  '), ValidationException::class);
        $cancelled = $this->sales->cancel($this->admin, $sale->sale->id, '  Apertura   equivocada ');
        $this->assertSame('Apertura equivocada', $cancelled->sale->cancellationReason);
        $this->assertSame($this->admin, $cancelled->sale->cancelledBy);
        $this->assertNotNull($cancelled->sale->cancelledAt);
        $this->assertEquals($line->items, $cancelled->items);
        $this->assertSame('10.1234', $cancelled->sale->total);
        $this->assertSame(0, $this->sales->sales($this->admin, new SaleQuery(state: SaleState::Open))->total);
        $this->rejects(fn () => $this->sales->addProduct($this->admin, $sale->sale->id, new AddProduct($this->product, 1)), SalesConflict::class);
        $this->rejects(fn () => $this->sales->resume($this->admin, $sale->sale->id), SalesConflict::class);
        $this->rejects(fn () => $this->sales->cancel($this->admin, $sale->sale->id, 'Otra'), SalesConflict::class);
    }

    public function test_closed_and_reopened_reserved_states_cannot_be_edited_cancelled_or_repriced(): void
    {
        foreach (['closed', 'reopened'] as $index => $state) {
            $sale = $this->open();
            $this->database->table('sales_sales')->where('id', $sale->sale->id)->update(['status' => $state, 'closed_at' => now()->utc(), 'folio' => '20261113000'.($index + 1)]);
            $this->rejects(fn () => $this->sales->editNote($this->admin, $sale->sale->id, 'Intento'), SalesConflict::class);
            $this->rejects(fn () => $this->sales->cancel($this->admin, $sale->sale->id, 'Intento'), SalesConflict::class);
            $this->assertSame($state, $this->database->table('sales_sales')->where('id', $sale->sale->id)->value('status'));
        }
    }

    public function test_removal_denies_missing_catalog_and_zero_uses_approved_non_destructive_flow(): void
    {
        $sale = $this->open();
        $line = $this->sales->addProduct($this->admin, $sale->sale->id, new AddProduct($this->product, 2))->items[0];
        $this->rejects(fn () => $this->sales->changeQuantity($this->admin, $sale->sale->id, $line->id, 0), SalesConflict::class);
        $this->rejects(fn () => $this->sales->removeItem($this->admin, $sale->sale->id, $line->id, 'TEST_APPROVED'), SalesConflict::class);
        $this->app->instance(LineRemovalApproval::class, new TestRemovalApproval);
        $sales = app(SalesOperations::class);
        $removed = $sales->changeQuantity($this->admin, $sale->sale->id, $line->id, 0, 'TEST_APPROVED');
        $this->assertSame('0.0000', $removed->sale->total);
        $this->assertSame(2, $removed->items[0]->quantity);
        $this->assertSame($this->admin, $removed->items[0]->removedBy);
        $this->assertNotNull($removed->items[0]->removedAt);
        $this->assertSame('TEST_APPROVED', $removed->items[0]->removalReasonCode);
        $this->assertSame(1, $this->database->table('sales_items')->count());
        $this->rejects(fn () => $sales->changeQuantity($this->admin, $sale->sale->id, $line->id, 1), ModelNotFoundException::class);
    }

    public function test_current_actor_permissions_do_not_inherit_admin_rights_or_bypass_status(): void
    {
        $waiter = $this->employee('waiter');
        $sale = $this->sales->open($waiter, new OpenSale(SaleType::Counter));
        $line = $this->sales->addProduct($waiter, $sale->sale->id, new AddProduct($this->product, 1))->items[0];
        $this->assertSame($waiter, $sale->sale->createdBy);
        $this->rejects(fn () => $this->sales->cancel($waiter, $sale->sale->id, 'No permitido'), AuthorizationException::class);
        $this->rejects(fn () => $this->sales->createTable($waiter, 'Sin privilegio'), AuthorizationException::class);
        $this->rejects(fn () => $this->sales->removeItem($waiter, $sale->sale->id, $line->id, 'TEST_APPROVED'), AuthorizationException::class);
        app(UserAdministration::class)->setActive($this->admin, $waiter, false);
        $this->rejects(fn () => $this->sales->sale($waiter, $sale->sale->id), AuthorizationException::class);
        $this->rejects(fn () => $this->sales->editNote($waiter, $sale->sale->id, 'Cambio'), AuthorizationException::class);
        $this->rejects(fn () => $this->sales->open(strtolower((string) Str::ulid()), new OpenSale(SaleType::Counter)), AuthorizationException::class);
    }

    public function test_supervisor_cashier_and_revoked_waiter_permissions_are_checked_each_time(): void
    {
        $supervisor = $this->employee('supervisor');
        $cashier = $this->employee('cashier');
        $waiter = $this->employee('waiter');
        $sale = $this->sales->open($cashier, new OpenSale(SaleType::Counter));
        $this->rejects(fn () => $this->sales->cancel($cashier, $sale->sale->id, 'Intento'), AuthorizationException::class);
        $this->assertSame(SaleState::Cancelled, $this->sales->cancel($supervisor, $sale->sale->id, 'Apertura equivocada')->sale->state);
        app(UserAdministration::class)->setRolePermissions($this->admin, 'waiter', ['catalog.products.view', 'sales.view']);
        $this->rejects(fn () => $this->sales->open($waiter, new OpenSale(SaleType::Counter)), AuthorizationException::class);
        $this->assertSame(1, $this->sales->sales($waiter)->total);
    }

    public function test_no_role_or_password_pending_actor_has_no_operational_access(): void
    {
        $id = app(UserAdministration::class)->create($this->admin, ['name' => 'Sin rol', 'username' => 'sales.no.role', 'password' => 'NoRoleInitial!123'])->id;
        $this->rejects(fn () => $this->sales->open($id, new OpenSale(SaleType::Counter)), AuthorizationException::class);
        app(UserAdministration::class)->assignRole($this->admin, $id, 'cashier');
        $this->rejects(fn () => $this->sales->open($id, new OpenSale(SaleType::Counter)), AuthorizationException::class);
        app(IdentityAccess::class)->changePassword($id, 'NoRoleInitial!123', 'NoRoleChanged!123');
        $this->assertSame($id, $this->sales->open($id, new OpenSale(SaleType::Counter))->sale->createdBy);
    }

    public function test_item_from_another_sale_cannot_be_modified(): void
    {
        $a = $this->open();
        $b = $this->open();
        $item = $this->sales->addProduct($this->admin, $a->sale->id, new AddProduct($this->product, 1))->items[0];
        $this->rejects(fn () => $this->sales->changeQuantity($this->admin, $b->sale->id, $item->id, 2), ModelNotFoundException::class);
        $this->assertSame('0.0000', $this->sales->sale($this->admin, $b->sale->id)->sale->total);
    }

    public function test_money_overflow_rolls_back_line_quantity_and_sale_totals(): void
    {
        $this->catalog->changePrice($this->admin, $this->product, '999999999999999.9999');
        $sale = $this->open();
        $full = $this->sales->addProduct($this->admin, $sale->sale->id, new AddProduct($this->product, 1));
        $this->assertSame('999999999999999.9999', $full->sale->total);
        $this->rejects(fn () => $this->sales->changeQuantity($this->admin, $sale->sale->id, $full->items[0]->id, 2), ValidationException::class);
        $other = $this->catalog->createProduct($this->admin, ['name' => 'Mínimo', 'category_id' => $this->category, 'price' => '0.0001']);
        $this->rejects(fn () => $this->sales->addProduct($this->admin, $sale->sale->id, new AddProduct($other->id, 1)), ValidationException::class);
        $after = $this->sales->sale($this->admin, $sale->sale->id);
        $this->assertCount(1, $after->items);
        $this->assertSame(1, $after->items[0]->quantity);
        $this->assertSame('999999999999999.9999', $after->sale->total);
    }

    public function test_decimal_arithmetic_matches_sql_server_at_precision_and_carry_boundaries(): void
    {
        foreach ([['0.0000', 100, '0.0001'], ['0.0001', 100, '1.9999'], ['1.9999', 17, '10.0001'],
            ['123456789.1234', 99, '1.0000'], ['9999999999999.9999', 100, '0.0001']] as [$price, $quantity, $extra]) {
            $expected = $this->database->selectOne('SELECT CAST(CAST(? AS decimal(19,4))*CAST(? AS decimal(10,0))+CAST(? AS decimal(19,4)) AS decimal(19,4)) AS amount', [$price, $quantity, $extra])->amount;
            // PDO SQL Server puede representar valores menores a uno como ".0001".
            $expected = str_starts_with((string) $expected, '.') ? '0'.$expected : (string) $expected;
            $sum = Amount::from($price)->multiply($quantity)->add(Amount::from($extra));
            $this->assertSame((string) $expected, $sum->value);
            $this->assertSame(Amount::from($price)->multiply($quantity)->value, $sum->subtract(Amount::from($extra))->value);
        }
    }

    public function test_queries_filter_sales_by_user_table_type_and_state_with_sql_pagination(): void
    {
        for ($i = 0; $i < 11; $i++) {
            $this->open();
        }
        $table = $this->sales->createTable($this->admin, 'Consulta');
        $tableSale = $this->sales->open($this->admin, new OpenSale(SaleType::Table, $table->id));
        $this->assertCount(2, $this->sales->sales($this->admin, new SaleQuery(page: 2, perPage: 10))->items);
        $filtered = $this->sales->sales($this->admin, new SaleQuery(state: SaleState::Open, type: SaleType::Table, tableId: $table->id, createdBy: $this->admin));
        $this->assertSame(1, $filtered->total);
        $this->assertSame($tableSale->sale->id, $filtered->items[0]->id);
        $this->database->enableQueryLog();
        $this->sales->sales($this->admin);
        $queries = $this->database->getQueryLog();
        $this->database->disableQueryLog();
        $this->assertCount(0, array_filter($queries, fn ($q) => str_contains($q['query'], 'sales_items')));
    }

    public function test_audit_failure_and_outer_rollback_leave_no_partial_financial_rows_or_after_commit_events(): void
    {
        $sale = $this->open();
        $before = $this->database->table('audit_security_events')->count();
        Event::listen(SecurityEvent::class, function (SecurityEvent $event): void {
            if ($event->type === 'sales.item_added') {
                throw new RuntimeException('Fallo de Audit inyectado.');
            }
        });
        $this->rejects(fn () => $this->sales->addProduct($this->admin, $sale->sale->id, new AddProduct($this->product, 1)), RuntimeException::class);
        $this->assertSame(0, $this->database->table('sales_items')->count());
        $this->assertSame($before, $this->database->table('audit_security_events')->count());
        $this->assertSame('0.0000', $this->sales->sale($this->admin, $sale->sale->id)->sale->total);
        $delivered = [];
        Event::listen(SaleChanged::class, function (SaleChanged $event) use (&$delivered): void {
            $delivered[] = $event;
        });
        $this->rejects(fn () => app(TransactionManager::class)->run(function (): void {
            $this->open();
            throw new RuntimeException('Rollback externo.');
        }), RuntimeException::class);
        $this->assertSame(1, $this->database->table('sales_sales')->count());
        $this->assertSame([], $delivered);
    }

    public function test_audit_is_single_minimal_utc_and_secondary_event_failure_does_not_revert_commit(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-13 12:00:00', 'America/Mexico_City'));
        Event::listen(SaleChanged::class, function (): void {
            throw new RuntimeException('Consumidor secundario caído.');
        });
        $sale = $this->open();
        $this->sales->editNote($this->admin, $sale->sale->id, 'Información que no va a Audit');
        $events = $this->database->table('audit_security_events')->where('record_id', $sale->sale->id)->get();
        $this->assertCount(2, $events);
        $this->assertSame(['sales.opened', 'sales.note_updated'], $events->pluck('event_type')->all());
        foreach ($events as $event) {
            $this->assertSame($this->admin, $event->actor_id);
            $this->assertSame('success', $event->result);
            $this->assertNull($event->change_data);
            $this->assertStringStartsWith('2026-11-13 18:00:00', (string) $event->occurred_at);
        }
        $this->assertStringNotContainsString('Información', $events->toJson());
        $this->assertSame(1, $this->database->table('sales_sales')->count());
    }

    public function test_domain_events_are_delivered_only_after_the_outer_commit(): void
    {
        $observed = [];
        Event::listen(SaleChanged::class, function (SaleChanged $event) use (&$observed): void {
            $observed[] = [$event->operation, $this->database->transactionLevel()];
        });
        app(TransactionManager::class)->run(function () use (&$observed): void {
            $this->open();
            $this->assertSame([], $observed);
        });
        $this->assertSame([['opened', 0]], $observed);
    }

    public function test_sql_schema_foreign_keys_checks_and_physical_deletion_guards(): void
    {
        $sale = $this->open();
        $item = $this->sales->addProduct($this->admin, $sale->sale->id, new AddProduct($this->product, 1))->items[0];
        foreach ([['quantity' => 0], ['unit_price' => '-1.0000'], ['line_total' => '123.0000'], ['product_id' => strtolower((string) Str::ulid())]] as $values) {
            $this->rejects(fn () => $this->database->transaction(fn () => $this->database->table('sales_items')->where('id', $item->id)->update($values)), QueryException::class);
        }
        foreach ([['status' => 'paid'], ['type' => 'delivery'], ['status' => 'closed'], ['total' => '-1.0000'], ['folio' => '202611130001']] as $values) {
            $this->rejects(fn () => $this->database->transaction(fn () => $this->database->table('sales_sales')->where('id', $sale->sale->id)->update($values)), QueryException::class);
        }
        $foreign = $this->database->select("SELECT delete_referential_action AS action FROM sys.foreign_keys WHERE parent_object_id IN (OBJECT_ID('sales_sales'),OBJECT_ID('sales_items'))");
        $this->assertCount(7, $foreign);
        $this->assertSame([0], array_values(array_unique(array_map(intval(...), array_column($foreign, 'action')))));
        $id = $this->database->selectOne("SELECT TYPE_NAME(user_type_id) AS type,max_length FROM sys.columns WHERE object_id=OBJECT_ID('sales_sales') AND name='id'");
        $this->assertSame('nchar', $id->type);
        $this->assertSame(52, (int) $id->max_length);
        $this->rejects(fn () => Sale::query()->findOrFail($sale->sale->id)->delete(), \LogicException::class);
        $this->rejects(fn () => SaleItem::query()->findOrFail($item->id)->delete(), \LogicException::class);
        $table = $this->sales->createTable($this->admin, 'Histórica');
        $this->rejects(fn () => DiningTable::query()->findOrFail($table->id)->delete(), \LogicException::class);
        $this->rejects(fn () => app(SalesStore::class)->createSale([]), \LogicException::class);
    }
}
