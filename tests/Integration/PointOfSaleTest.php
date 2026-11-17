<?php

namespace Tests\Integration;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Modules\Auth\Application\SessionRegistry;
use Modules\Auth\Infrastructure\Models\AccessSession;
use Modules\Cash\Contracts\ActiveCashSession;
use Modules\Catalog\Contracts\CatalogAdministration;
use Modules\Sales\Contracts\AddProduct;
use Modules\Sales\Contracts\CriticalSalesWrites;
use Modules\Sales\Contracts\OpenSale;
use Modules\Sales\Contracts\SalesOperations;
use Modules\Sales\Domain\SaleType;
use Modules\Sales\Http\Livewire\PointOfSale;
use Modules\Sales\Infrastructure\UnavailableCashSession;
use Modules\Users\Application\BootstrapAdministrator;
use Modules\Users\Contracts\IdentityAccess;
use Modules\Users\Contracts\UserAdministration;
use Tests\Fixtures\Sales\TestCashSession;
use Tests\Fixtures\Sales\TestCriticalSalesWrites;
use Tests\Support\SalesSqlServerTestCase;

class PointOfSaleTest extends SalesSqlServerTestCase
{
    private string $admin;

    private string $category;

    private string $product;

    private array $cookies = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = app(BootstrapAdministrator::class)->create('POS fixture', 'pos.admin', 'PosInitial!123');
        app(IdentityAccess::class)->changePassword($this->admin, 'PosInitial!123', 'PosChanged!123');
        $catalog = app(CatalogAdministration::class);
        $this->category = $catalog->createCategory($this->admin, ['name' => 'Bebidas'])->id;
        $this->product = $catalog->createProduct($this->admin, ['name' => 'Café frío', 'category_id' => $this->category, 'price' => '10.1234'])->id;
    }

    private function web(string $method, string $path, array $data = [], bool $livewire = false): TestResponse
    {
        Livewire::flushState();
        Auth::forgetGuards();
        Auth::shouldUse('web');
        $response = $livewire
            ? $this->call($method, $path, [], $this->cookies, [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_LIVEWIRE' => 'true'], json_encode($data, JSON_THROW_ON_ERROR))
            : $this->call($method, $path, $data, $this->cookies, [], ['HTTP_ACCEPT' => 'text/html']);
        foreach ($response->headers->getCookies() as $cookie) {
            $this->cookies[$cookie->getName()] = $cookie->getValue();
        }

        return $response;
    }

    private function login(string $username = 'pos.admin', string $password = 'PosChanged!123'): void
    {
        $this->web('POST', '/login', compact('username', 'password'))->assertRedirect(route('auth.account'));
        $this->web('GET', '/account')->assertOk();
    }

    private function fixtures(): void
    {
        $this->app->instance(ActiveCashSession::class, new TestCashSession(strtolower((string) Str::ulid())));
        $this->app->instance(CriticalSalesWrites::class, new TestCriticalSalesWrites);
    }

    private function sale(): string
    {
        $sales = app(SalesOperations::class);
        $sale = $sales->open($this->admin, new OpenSale(SaleType::Counter, note: 'Nota histórica'));

        return $sales->addProduct($this->admin, $sale->sale->id, new AddProduct($this->product, 2, 'Sin hielo'))->sale->id;
    }

    private function protocol(TestResponse $response): array
    {
        $document = new DOMDocument;
        $document->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        foreach ((new DOMXPath($document))->query('//*[@*[name()="wire:snapshot"]]') as $element) {
            $snapshot = $element->getAttribute('wire:snapshot');
            if (json_decode($snapshot, true)['memo']['name'] === 'sales.pos') {
                preg_match('/data-update-uri="([^"]+)"/', $response->getContent(), $matches);

                return [$snapshot, html_entity_decode($matches[1])];
            }
        }
        $this->fail('Debe montar el componente POS real.');
    }

    private function payload(string $snapshot, string $method = 'refreshSale', array $params = [], array $updates = []): array
    {
        return ['_token' => session()->token(), 'components' => [['snapshot' => $snapshot, 'updates' => $updates, 'calls' => [['path' => '', 'method' => $method, 'params' => $params]]]]];
    }

    public function test_navigation_authentication_and_permissions(): void
    {
        $this->web('GET', '/pos')->assertRedirect(route('login'));
        $this->login();
        $this->web('GET', '/account')->assertSee('Punto de venta')->assertSee(route('sales.pos'));
        $this->web('GET', '/pos')->assertOk()->assertSee('Tu espacio de venta')->assertSee('POS fixture')->assertSee('Cambiar empleado');
        $users = app(UserAdministration::class);
        $id = $users->create($this->admin, ['name' => 'Sin permisos', 'username' => 'pos.none', 'password' => 'EmployeeInitial!123'])->id;
        app(IdentityAccess::class)->changePassword($id, 'EmployeeInitial!123', 'EmployeeChanged!123');
        $this->login('pos.none', 'EmployeeChanged!123');
        $this->web('GET', '/pos')->assertForbidden();
        $this->web('GET', '/account')->assertDontSee(route('sales.pos'));
    }

    public function test_default_cash_and_critical_dependencies_block_even_forged_actions(): void
    {
        $this->login();
        Livewire::test(PointOfSale::class)->assertSee('No hay sesión de caja abierta')->assertSee('No hay ventas abiertas')
            ->call('startSale')->assertHasErrors('operation')->assertSee('Operación no disponible');
        $this->assertSame(0, $this->database->table('sales_sales')->count());
        $this->assertSame(0, $this->database->table('sales_items')->count());
        $this->assertSame(0, $this->database->table('audit_security_events')->where('event_type', 'sales.opened')->count());
    }

    public function test_catalog_search_categories_pagination_and_availability_use_existing_queries(): void
    {
        $catalog = app(CatalogAdministration::class);
        $other = $catalog->createCategory($this->admin, ['name' => 'Postres'])->id;
        $hidden = $catalog->createProduct($this->admin, ['name' => 'Producto oculto', 'category_id' => $other, 'price' => '1.0000'])->id;
        $catalog->setCategoryActive($this->admin, $other, false);
        $inactive = $catalog->createProduct($this->admin, ['name' => 'Producto inactivo', 'category_id' => $this->category, 'price' => '1.0000'])->id;
        $catalog->setProductActive($this->admin, $inactive, false);
        $deleted = $catalog->createProduct($this->admin, ['name' => 'Producto eliminado', 'category_id' => $this->category, 'price' => '1.0000'])->id;
        $catalog->deleteProduct($this->admin, $deleted);
        for ($i = 0; $i < 11; $i++) {
            $catalog->createProduct($this->admin, ['name' => 'Zulu '.$i, 'category_id' => $this->category, 'price' => '1.0000']);
        }
        $this->login();
        Livewire::test(PointOfSale::class)->assertSee('Café frío')->assertDontSee('Producto oculto')->assertDontSee('Producto inactivo')->assertDontSee('Producto eliminado')
            ->assertSee('12 disponibles')->call('gotoPage', 'productPage', 2)->assertSet('productPage', 2)
            ->set('search', '  CAFE  FRIO ')->assertSet('productPage', 1)->assertSee('Café frío')->assertDontSee('Zulu 0')
            ->call('selectCategory', $this->category)->assertSet('categoryName', 'Bebidas')->set('search', 'sin coincidencia')->assertSee('No hay productos disponibles con estos filtros')
            ->call('clearSearch')->assertSet('search', '')->assertSet('categoryFilter', '');
        $this->assertNotEmpty($hidden);
    }

    public function test_tables_free_occupied_inactive_and_refresh(): void
    {
        $this->fixtures();
        $sales = app(SalesOperations::class);
        $free = $sales->createTable($this->admin, 'Mesa libre');
        $occupied = $sales->createTable($this->admin, 'Mesa ocupada');
        $inactive = $sales->createTable($this->admin, 'Mesa inactiva');
        $sales->updateTable($this->admin, $inactive->id, $inactive->name, false);
        $sale = $sales->open($this->admin, new OpenSale(SaleType::Table, $occupied->id));
        $this->login();
        Livewire::test(PointOfSale::class)->call('selectMode', 'table')->assertSee('Libre · Seleccionar')->assertSee('Ocupada · Ver venta')->assertSee('Inactiva')
            ->call('selectTable', $occupied->id)->assertSet('saleId', $sale->sale->id)->assertSee('Venta de mesa')
            ->call('selectTable', $inactive->id)->assertHasErrors('operation')->call('selectTable', $free->id)->assertSet('tableId', $free->id)->assertSet('saleId', null);
    }

    public function test_historical_prices_and_totals_never_follow_current_catalog_price(): void
    {
        $this->fixtures();
        $id = $this->sale();
        app(CatalogAdministration::class)->changePrice($this->admin, $this->product, '99.0000');
        $this->login();
        Livewire::test(PointOfSale::class)->call('selectSale', $id)->assertSee('20.2468')->assertSee('Precio aplicado')->assertSee('Sin hielo')->assertSet('saleNote', 'Nota histórica')
            ->assertSet('currentSale.items.0.unit_price', '10.1234')->assertSet('currentSale.total', '20.2468')->assertDontSee('cash_session_id');
    }

    public function test_add_product_and_repeated_submit_consolidate_using_real_services(): void
    {
        $this->fixtures();
        $this->login();
        $screen = Livewire::test(PointOfSale::class)->call('startSale')->assertHasNoErrors();
        $id = $screen->get('saleId');
        $screen->call('chooseProduct', $this->product)->set('quantity', '2')->set('itemNote', ' Sin   hielo ')->call('addProduct')->assertHasNoErrors()->assertSet('currentSale.total', '20.2468')
            ->call('chooseProduct', $this->product)->set('quantity', '1')->set('itemNote', 'Sin hielo')->call('addProduct')->assertHasNoErrors()->assertSet('currentSale.total', '30.3702');
        $this->assertSame(1, $this->database->table('sales_items')->count());
        $this->assertSame(2, $this->database->table('audit_security_events')->where('event_type', 'sales.item_added')->where('record_id', $id)->count());
        $screen->call('addProduct')->assertStatus(422);
        $this->assertSame(3, (int) $this->database->table('sales_items')->value('quantity'));
    }

    public function test_quantity_and_note_validation_preserve_text_and_domain_normalization(): void
    {
        $this->fixtures();
        $id = $this->sale();
        $this->login();
        $screen = Livewire::test(PointOfSale::class)->call('selectSale', $id);
        $item = $screen->get('currentSale.items')[0]['id'];
        $screen->call('editItem', $item)->set('quantity', '1.5')->set('itemNote', 'Texto pendiente')->call('saveQuantity')->assertHasErrors('quantity')->assertSet('itemNote', 'Texto pendiente')
            ->set('quantity', '101')->call('saveQuantity')->assertHasErrors('quantity')->set('quantity', '3')->call('saveQuantity')->assertHasNoErrors()->assertSet('currentSale.total', '30.3702')
            ->set('itemNote', '  Poco   hielo ')->call('saveItemNote')->assertHasNoErrors()->assertSet('currentSale.items.0.note', 'Poco hielo')
            ->set('saleNote', str_repeat('x', 151))->call('saveSaleNote')->assertHasErrors('saleNote')->assertSet('saleNote', str_repeat('x', 151))
            ->set('saleNote', '  Para   llevar ')->call('saveSaleNote')->assertHasNoErrors()->assertSet('saleNote', 'Para llevar')
            ->set('itemNote', '<script>alert(1)</script>')->call('saveItemNote')->assertHasErrors()->assertDontSee('<script>alert(1)</script>', false);
        $this->assertSame(1, $this->database->table('audit_security_events')->where('event_type', 'sales.quantity_changed')->count());
    }

    public function test_pause_resume_conflicts_and_explicit_refresh_preserve_pending_note(): void
    {
        $this->fixtures();
        $id = $this->sale();
        $this->login();
        $screen = Livewire::test(PointOfSale::class)->call('selectSale', $id)->set('saleNote', 'Pendiente');
        app(SalesOperations::class)->editNote($this->admin, $id, 'Cambio de otro cliente');
        $screen->call('saveSaleNote')->assertHasErrors('operation')->assertSet('saleNote', 'Pendiente')->assertSee('Actualiza')
            ->call('refreshSale')->assertSet('saleNote', 'Pendiente')->call('saveSaleNote')->assertHasNoErrors()
            ->call('pauseSale')->assertSet('currentSale.state', 'paused')->assertSet('currentSale.total', '20.2468')
            ->call('resumeSale')->assertSet('currentSale.state', 'open')->call('resumeSale')->assertHasErrors('operation');
        $this->assertNull($this->database->table('sales_sales')->where('id', $id)->value('folio'));
    }

    public function test_zero_quantity_cannot_remove_line_and_foreign_item_is_not_editable(): void
    {
        $this->fixtures();
        $id = $this->sale();
        $other = $this->sale();
        $this->login();
        $screen = Livewire::test(PointOfSale::class)->call('selectSale', $id);
        $item = $screen->get('currentSale.items')[0]['id'];
        $screen->call('editItem', $item)->set('quantity', '1')->call('saveQuantity')->call('adjustQuantity', $item, -1)->assertHasErrors('operation')->assertSee('motivo autorizado');
        $foreign = $this->database->table('sales_items')->where('sale_id', $other)->value('id');
        $screen->call('editItem', $foreign)->assertNotFound();
        $this->assertSame(2, $this->database->table('sales_items')->count());
    }

    public function test_real_protocol_csrf_and_revoked_session_are_enforced(): void
    {
        $this->login();
        [$snapshot,$uri] = $this->protocol($this->web('GET', '/pos')->assertOk());
        $payload = $this->payload($snapshot);
        $this->app->instance('env', 'csrf-check');
        try {
            $missing = $payload;
            unset($missing['_token']);
            $this->web('POST', $uri, $missing, true)->assertStatus(419);
            $this->web('POST', $uri, $payload, true)->assertOk();
        } finally {
            $this->app->instance('env', 'testing');
        }
        $session = AccessSession::query()->where('user_id', $this->admin)->whereNull('revoked_at')->firstOrFail();
        app(SessionRegistry::class)->revokeAuthorized($this->admin, $session->id);
        $this->web('POST', $uri, $payload, true)->assertUnauthorized();
        $this->assertSame(0, $this->database->table('sales_sales')->count());
    }

    public function test_removed_permissions_and_inactive_user_cannot_use_old_snapshot(): void
    {
        $users = app(UserAdministration::class);
        $id = $users->create($this->admin, ['name' => 'Empleado POS', 'username' => 'pos.waiter', 'password' => 'EmployeeInitial!123'])->id;
        $users->assignRole($this->admin, $id, 'waiter');
        app(IdentityAccess::class)->changePassword($id, 'EmployeeInitial!123', 'EmployeeChanged!123');
        $this->login('pos.waiter', 'EmployeeChanged!123');
        [$snapshot,$uri] = $this->protocol($this->web('GET', '/pos')->assertOk());
        $users->setRolePermissions($this->admin, 'waiter', ['catalog.products.view']);
        $this->web('POST', $uri, $this->payload($snapshot), true)->assertForbidden();
        $users->setActive($this->admin, $id, false);
        $this->web('POST', $uri, $this->payload($snapshot), true)->assertUnauthorized();
    }

    public function test_locked_current_sale_cannot_be_replaced_by_client_payload(): void
    {
        $this->login();
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(PointOfSale::class)->set('currentSale', ['total' => '0.0000', 'created_by' => $this->admin]);
    }

    public function test_internal_errors_are_generic_and_keep_pending_input_in_both_debug_modes(): void
    {
        $this->login();
        $catalog = $this->createStub(CatalogAdministration::class);
        $catalog->method('products')->willThrowException(new \RuntimeException('SQLSTATE PRIVATE_PASSWORD C:\\private\\server.php'));
        $this->app->instance(CatalogAdministration::class, $catalog);
        foreach ([true, false] as $debug) {
            config(['app.debug' => $debug]);
            Livewire::test(PointOfSale::class)->assertSee('No fue posible conectar')->assertDontSee('SQLSTATE')->assertDontSee('PRIVATE_PASSWORD')->assertDontSee('server.php');
        }
    }

    public function test_pin_switch_rejects_snapshot_of_previous_employee_and_removes_admin_navigation(): void
    {
        $users = app(UserAdministration::class);
        $employee = $users->create($this->admin, ['name' => 'Empleado PIN POS', 'username' => 'pos.pin', 'password' => 'EmployeeInitial!123'])->id;
        $users->assignRole($this->admin, $employee, 'waiter');
        app(IdentityAccess::class)->changePassword($employee, 'EmployeeInitial!123', 'EmployeeChanged!123');
        $this->login('pos.pin', 'EmployeeChanged!123');
        $this->web('POST', '/auth/pin', ['current_password' => 'EmployeeChanged!123', 'pin' => '0042', 'pin_confirmation' => '0042'])->assertRedirect();
        $this->login();
        $this->web('POST', '/auth/terminal', ['current_password' => 'PosChanged!123'])->assertRedirect();
        [$snapshot,$uri] = $this->protocol($this->web('GET', '/pos')->assertOk());
        $this->web('POST', '/auth/employee', ['username' => 'pos.pin', 'pin' => '0042'])->assertRedirect(route('auth.account'));
        $this->web('POST', $uri, $this->payload($snapshot), true)->assertForbidden();
        $this->web('GET', '/pos')->assertOk()->assertSee('Empleado PIN POS')->assertDontSee('Administración');
        $this->assertSame(0, $this->database->table('sales_sales')->count());
    }

    public function test_product_images_and_text_fallback_are_present_without_private_storage_keys(): void
    {
        $this->database->table('catalog_products')->where('id', $this->product)->update(['image_disk' => 'public', 'image_key' => 'catalog/products/01arz3ndektsv4rrffq69g5fav.webp']);
        app(CatalogAdministration::class)->createProduct($this->admin, ['name' => 'Sin fotografía', 'category_id' => $this->category, 'price' => '1.0000']);
        $this->login();
        $this->web('GET', '/pos')->assertOk()->assertSee('loading="lazy"', false)->assertSee('Sin fotografía · sin imagen')->assertSee('/storage/catalog/products/01arz3ndektsv4rrffq69g5fav.webp')->assertDontSee('image_disk');
    }

    public function test_closed_and_cancelled_sales_cannot_be_changed_by_forging_ui_actions(): void
    {
        $this->fixtures();
        $id = $this->sale();
        $this->login();
        app(SalesOperations::class)->cancel($this->admin, $id, 'Motivo aislado de dominio');
        Livewire::test(PointOfSale::class)->call('selectSale', $id)->set('saleNote', 'Prohibida')->call('saveSaleNote')->assertHasErrors('operation')->call('resumeSale')->assertHasErrors('operation')->assertSet('currentSale.state', 'cancelled');
        $other = $this->sale();
        $this->database->table('sales_sales')->where('id', $other)->update(['status' => 'closed', 'closed_at' => now()->utc(), 'folio' => '202611130001']);
        Livewire::test(PointOfSale::class)->call('selectSale', $other)->call('pauseSale')->assertHasErrors('operation')->assertSet('currentSale.state', 'closed');
        $this->assertSame('20.2468', app(SalesOperations::class)->sale($this->admin, $other)->sale->total);
    }

    public function test_disabled_sale_mutation_still_requires_cash_on_server(): void
    {
        $this->fixtures();
        $id = $this->sale();
        $this->app->instance(ActiveCashSession::class, new UnavailableCashSession);
        $this->login();
        Livewire::test(PointOfSale::class)->call('selectSale', $id)->set('saleNote', 'Intento prohibido')->call('saveSaleNote')->assertHasErrors('operation')->assertSet('saleNote', 'Intento prohibido');
        $this->assertSame('Nota histórica', app(SalesOperations::class)->sale($this->admin, $id)->sale->note);
        $this->assertSame(0, $this->database->table('audit_security_events')->where('event_type', 'sales.note_updated')->count());
    }

    public function test_empty_catalog_tables_and_paused_sales_have_explicit_states(): void
    {
        $catalog = app(CatalogAdministration::class);
        $catalog->deleteProduct($this->admin, $this->product);
        $catalog->deleteCategory($this->admin, $this->category);
        $this->login();
        Livewire::test(PointOfSale::class)->assertSee('No hay categorías disponibles')->assertSee('No hay productos disponibles con estos filtros')
            ->call('selectMode', 'table')->assertSee('Todavía no hay mesas registradas')
            ->call('selectMode', 'counter')->call('selectCounterState', 'paused')->assertSee('No hay ventas pausadas');
        $this->assertSame(0, $this->database->table('sales_sales')->count());
    }
}
