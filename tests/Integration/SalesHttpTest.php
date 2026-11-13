<?php

namespace Tests\Integration;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Auth\Infrastructure\Models\AccessSession;
use Modules\Cash\Contracts\ActiveCashSession;
use Modules\Catalog\Contracts\CatalogAdministration;
use Modules\Sales\Contracts\CancellationApproval;
use Modules\Sales\Contracts\CriticalSalesWrites;
use Modules\Sales\Contracts\LineRemovalApproval;
use Modules\Sales\Contracts\OpenSale;
use Modules\Sales\Contracts\SalesOperations;
use Modules\Sales\Domain\SaleType;
use Modules\Users\Application\BootstrapAdministrator;
use Modules\Users\Contracts\IdentityAccess;
use Modules\Users\Contracts\UserAdministration;
use Tests\Fixtures\Sales\TestCancellationApproval;
use Tests\Fixtures\Sales\TestCashSession;
use Tests\Fixtures\Sales\TestCriticalSalesWrites;
use Tests\Fixtures\Sales\TestRemovalApproval;
use Tests\Support\SalesSqlServerTestCase;

class SalesHttpTest extends SalesSqlServerTestCase
{
    private string $admin;

    private string $token;

    private string $product;

    private SalesOperations $sales;

    private static array $samples = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = app(BootstrapAdministrator::class)->create('Sales HTTP fixture', 'sales.http', 'SalesInitial!123');
        app(IdentityAccess::class)->changePassword($this->admin, 'SalesInitial!123', 'SalesChanged!123');
        $this->token = $this->login();
        $catalog = app(CatalogAdministration::class);
        $category = $catalog->createCategory($this->admin, ['name' => 'Bebidas'])->id;
        $this->product = $catalog->createProduct($this->admin, ['name' => 'Fresa', 'category_id' => $category, 'price' => '10.1234'])->id;
        $this->sales = app(SalesOperations::class);
    }

    public static function tearDownAfterClass(): void
    {
        file_put_contents(dirname(__DIR__, 2).'/.tools/sales-1.5b-contract-responses.json', json_encode(self::$samples, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        self::$samples = [];
        parent::tearDownAfterClass();
    }

    private function login(string $username = 'sales.http', string $password = 'SalesChanged!123'): string
    {
        Auth::forgetGuards();

        return $this->postJson('/api/v1/auth/login', compact('username', 'password'))->assertOk()->json('data.token');
    }

    private function api(string $method, string $path, array $data = [], ?string $revision = null, ?string $key = null, ?string $token = null): TestResponse
    {
        Auth::forgetGuards();
        // Una app de test vive entre requests; renovar controladores al sustituir
        // contratos de fixtures igual que en el arranque de una petición nativa.
        foreach (app('router')->getRoutes() as $route) {
            $route->flushController();
        }
        $response = $this->json($method, '/api/v1/sales'.$path, $data, ['Authorization' => 'Bearer '.($token ?? $this->token),
            'X-Request-Id' => 'sales-http', ...($revision === null ? [] : ['If-Match' => '"'.$revision.'"']),
            ...($key === null ? [] : ['Idempotency-Key' => $key])]);
        $response->assertHeader('X-Request-Id', 'sales-http')->assertJsonPath('meta.request_id', 'sales-http');
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $pattern = preg_replace('~/[0-7][0-9a-hjkmnp-tv-z]{25}(?=/|$)~i', '/{id}', parse_url('/sales'.$path, PHP_URL_PATH));
        if ($pattern === '/sales/not-ulid') {
            $pattern = '/sales/{id}';
        }
        if (str_contains($pattern, '/items/{id}')) {
            $pattern = str_replace('/items/{id}', '/items/{itemId}', $pattern);
        }
        self::$samples[] = ['method' => strtolower($method), 'path' => $pattern, 'status' => $response->status(), 'body' => $response->json()];

        return $response;
    }

    private function fixtures(): void
    {
        $this->app->instance(ActiveCashSession::class, new TestCashSession(strtolower((string) Str::ulid())));
        $this->app->instance(CriticalSalesWrites::class, new TestCriticalSalesWrites);
        $this->sales = app(SalesOperations::class);
    }

    private function open(): TestResponse
    {
        return $this->api('POST', '', ['type' => 'counter', 'note' => 'Inicial'], key: strtolower((string) Str::ulid()))->assertCreated();
    }

    public function test_tables_crud_status_filters_pagination_and_stale_writes(): void
    {
        $created = $this->api('POST', '/tables', ['name' => '  Mesa   Azul ', 'display_order' => 3])->assertCreated()->assertJsonPath('data.name', 'Mesa Azul')->assertJsonPath('data.availability', 'free');
        $id = $created->json('data.id');
        $revision = $created->json('data.revision');
        $this->api('GET', '/tables/'.$id)->assertOk();
        $changed = $this->api('PUT', '/tables/'.$id, ['name' => 'Mesa Verde', 'is_active' => true, 'display_order' => 1], $revision)->assertOk();
        $this->api('PUT', '/tables/'.$id, ['name' => 'Sobrescritura', 'is_active' => true], $revision)->assertConflict()->assertJsonPath('code', 'CONFLICT');
        $inactive = $this->api('PATCH', '/tables/'.$id.'/status', ['is_active' => false], $changed->json('data.revision'))->assertOk()->assertJsonPath('data.availability', 'inactive');
        $this->api('PATCH', '/tables/'.$id.'/status', ['is_active' => true], $inactive->json('data.revision'))->assertOk()->assertJsonPath('data.availability', 'free');
        for ($i = 0; $i < 10; $i++) {
            $this->sales->createTable($this->admin, 'Z Mesa '.$i);
        }
        $this->api('GET', '/tables?per_page=10&page=1&is_active=true&is_occupied=false')->assertOk()->assertJsonCount(10, 'data.tables')->assertJsonPath('meta.total', 11)->assertJsonPath('data.tables.0.name', 'Mesa Verde');
        $this->api('GET', '/tables?search=Verde')->assertOk()->assertJsonCount(1, 'data.tables');
        $this->api('GET', '/tables?per_page=11')->assertUnprocessable();
        $this->api('GET', '/tables?order=unsafe')->assertUnprocessable();
        $this->api('PUT', '/tables/'.$id, ['name' => 'Sin precondicion', 'is_active' => true])->assertConflict();
        $this->api('POST', '/tables', ['name' => 'MESA VERDE'])->assertConflict();
    }

    public function test_open_and_retries_are_blocked_without_real_dependencies_and_no_partial_sales(): void
    {
        foreach ([false, true] as $debug) {
            config(['app.debug' => $debug]);
            for ($i = 0; $i < 2; $i++) {
                $this->api('POST', '', ['type' => 'counter'], key: 'same-critical-key-0001')->assertStatus(503)->assertJsonPath('code', 'SERVICE_UNAVAILABLE');
            }
        }
        $this->assertSame(0, $this->database->table('sales_sales')->count());
        $this->assertSame(0, $this->database->table('audit_security_events')->where('event_type', 'sales.opened')->count());
        // El adaptador de idempotencia de prueba NO reemplaza Cash.
        $this->app->instance(CriticalSalesWrites::class, new TestCriticalSalesWrites);
        $this->api('POST', '', ['type' => 'counter'], key: 'cash-required-key-0001')->assertStatus(503);
        $this->assertSame(0, $this->database->table('sales_sales')->count());
    }

    public function test_sale_and_item_input_reject_protected_fields_and_invalid_values(): void
    {
        foreach ([['type' => 'other'], ['type' => 'table'], ['type' => 'counter', 'table_id' => (string) Str::ulid()], ['type' => 'counter', 'total' => '0.0000'], ['type' => 'counter', 'user_id' => $this->admin], ['type' => 'counter', 'cash_session_id' => (string) Str::ulid()]] as $data) {
            $this->api('POST', '', $data, key: 'validation-key-00001')->assertUnprocessable();
        }
        $this->api('POST', '', ['type' => 'counter'])->assertUnprocessable();
        $this->fixtures();
        $sale = $this->open();
        $id = $sale->json('data.id');
        $revision = $sale->json('data.revision');
        foreach ([0, -1, 101, 1.5, '2', true] as $quantity) {
            $this->api('POST', '/'.$id.'/items', ['product_id' => $this->product, 'quantity' => $quantity], $revision, 'invalid-quantity-key')->assertUnprocessable();
        }
        foreach (['unit_price', 'line_total', 'subtotal', 'discount', 'folio', 'created_by', 'audit'] as $field) {
            $this->api('POST', '/'.$id.'/items', ['product_id' => $this->product, 'quantity' => 1, $field => 'forbidden'], $revision, 'protected-field-key')->assertUnprocessable();
        }
        $this->api('PATCH', '/'.$id.'/note', ['note' => str_repeat('x', 151)], $revision)->assertUnprocessable();
        $this->api('PATCH', '/'.$id.'/note', ['note' => '<script>'], $revision)->assertUnprocessable();
        $this->assertSame(0, $this->database->table('sales_items')->count());
        $this->api('GET', '/not-ulid')->assertNotFound();
        $this->api('GET', '/'.strtolower((string) Str::ulid()))->assertNotFound();
    }

    public function test_http_items_snapshots_consolidation_money_and_authorized_actor(): void
    {
        $this->fixtures();
        $created = $this->open();
        $id = $created->json('data.id');
        $added = $this->api('POST', '/'.$id.'/items', ['product_id' => $this->product, 'quantity' => 2, 'note' => 'Sin hielo'], $created->json('data.revision'), 'add-first-key-00001')->assertOk()->assertJsonPath('data.total', '20.2468');
        $this->api('POST', '/'.$id.'/items', ['product_id' => $this->product, 'quantity' => 2, 'note' => 'Sin hielo'], $created->json('data.revision'), 'add-first-key-00001')->assertOk()->assertJsonPath('data.total', '20.2468');
        $this->api('POST', '/'.$id.'/items', ['product_id' => $this->product, 'quantity' => 3], $added->json('data.revision'), 'add-first-key-00001')->assertConflict();
        $added = $this->api('POST', '/'.$id.'/items', ['product_id' => $this->product, 'quantity' => 1, 'note' => 'Sin hielo'], $added->json('data.revision'), 'add-second-key-0001')->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.items.0.quantity', 3)->assertJsonPath('data.total', '30.3702');
        $this->api('GET', '/'.$id.'/items')->assertOk()->assertJsonPath('data.items.0.name', 'Fresa');
        $this->api('GET', '/'.$id)->assertOk()->assertJsonPath('data.created_by', $this->admin)->assertJsonMissingPath('data.cash_session_id')->assertJsonMissingPath('data.password');
        app(CatalogAdministration::class)->updateProduct($this->admin, $this->product, ['name' => 'Nueva Fresa', 'price' => '99.0000']);
        $this->api('GET', '/'.$id.'/items')->assertJsonPath('data.items.0.name', 'Fresa')->assertJsonPath('data.items.0.unit_price', '10.1234');
        $this->assertSame(2, $this->database->table('audit_security_events')->where('event_type', 'sales.item_added')->where('actor_id', $this->admin)->where('record_id', $id)->count());
        $this->assertSame(1, $this->database->table('sales_items')->count());
    }

    public function test_quantity_notes_pause_resume_and_conflict_preserve_totals(): void
    {
        $this->fixtures();
        $created = $this->open();
        $id = $created->json('data.id');
        $added = $this->api('POST', '/'.$id.'/items', ['product_id' => $this->product, 'quantity' => 2], $created->json('data.revision'), 'mutation-add-key-001')->assertOk();
        $item = $added->json('data.items.0.id');
        $old = $added->json('data.revision');
        $quantity = $this->api('PATCH', '/'.$id.'/items/'.$item.'/quantity', ['quantity' => 3], $old)->assertOk()->assertJsonPath('data.total', '30.3702');
        $this->api('PATCH', '/'.$id.'/items/'.$item.'/quantity', ['quantity' => 4], $old)->assertConflict();
        $note = $this->api('PATCH', '/'.$id.'/items/'.$item.'/note', ['note' => '  Poco   hielo  '], $quantity->json('data.revision'))->assertOk()->assertJsonPath('data.items.0.note', 'Poco hielo')->assertJsonPath('data.total', '30.3702');
        $this->api('PATCH', '/'.$id.'/items/'.$item.'/note', ['note' => 'Sobrescribir'], $quantity->json('data.revision'))->assertConflict();
        $general = $this->api('PATCH', '/'.$id.'/note', ['note' => 'Para llevar'], $note->json('data.revision'))->assertOk();
        $paused = $this->api('POST', '/'.$id.'/pause', revision: $general->json('data.revision'))->assertOk()->assertJsonPath('data.state', 'paused')->assertJsonPath('data.total', '30.3702');
        $this->api('PATCH', '/'.$id.'/note', ['note' => 'No editable'], $paused->json('data.revision'))->assertConflict();
        $resumed = $this->api('POST', '/'.$id.'/resume', revision: $paused->json('data.revision'))->assertOk()->assertJsonPath('data.state', 'open')->assertJsonPath('data.note', 'Para llevar');
        $this->api('POST', '/'.$id.'/resume', revision: $resumed->json('data.revision'))->assertConflict();
        $this->assertNull($this->database->table('sales_sales')->where('id', $id)->value('folio'));
        $this->assertSame(1, $this->database->table('audit_security_events')->where('event_type', 'sales.quantity_changed')->count());
    }

    public function test_reason_catalog_dependencies_fail_closed_and_test_adapters_preserve_history(): void
    {
        $this->fixtures();
        $created = $this->open();
        $id = $created->json('data.id');
        $added = $this->api('POST', '/'.$id.'/items', ['product_id' => $this->product, 'quantity' => 1], $created->json('data.revision'), 'reason-test-add-001')->assertOk();
        $item = $added->json('data.items.0.id');
        $revision = $added->json('data.revision');
        $this->api('DELETE', '/'.$id.'/items/'.$item, revision: $revision)->assertUnprocessable();
        $this->api('DELETE', '/'.$id.'/items/'.$item, ['reason_code' => 'TEST_APPROVED'], $revision)->assertConflict();
        $this->api('POST', '/'.$id.'/cancel', revision: $revision)->assertUnprocessable();
        $this->api('POST', '/'.$id.'/cancel', ['reason_code' => 'TEST_APPROVED'], $revision)->assertStatus(503);
        $this->app->instance(LineRemovalApproval::class, new TestRemovalApproval);
        $removed = $this->api('DELETE', '/'.$id.'/items/'.$item, ['reason_code' => 'TEST_APPROVED'], $revision)->assertOk()->assertJsonPath('data.total', '0.0000');
        $this->assertSame(1, $this->database->table('sales_items')->count());
        $this->app->instance(CancellationApproval::class, new TestCancellationApproval);
        $cancelled = $this->api('POST', '/'.$id.'/cancel', ['reason_code' => 'TEST_APPROVED'], $removed->json('data.revision'))->assertOk()->assertJsonPath('data.state', 'cancelled');
        $this->api('POST', '/'.$id.'/resume', revision: $cancelled->json('data.revision'))->assertConflict();
        $this->api('PATCH', '/'.$id.'/note', ['note' => 'No permitido'], $cancelled->json('data.revision'))->assertConflict();
        $this->assertSame(1, $this->database->table('audit_security_events')->where('event_type', 'sales.cancelled')->count());
    }

    public function test_table_occupancy_and_sale_filters_are_derived_from_sql(): void
    {
        $this->fixtures();
        $table = $this->sales->createTable($this->admin, 'Mesa ocupada');
        $sale = $this->sales->open($this->admin, new OpenSale(SaleType::Table, $table->id));
        $this->api('GET', '/tables/'.$table->id)->assertOk()->assertJsonPath('data.availability', 'occupied')->assertJsonPath('data.active_sale_id', $sale->sale->id);
        $occupied = $this->api('GET', '/tables/'.$table->id);
        $this->api('PATCH', '/tables/'.$table->id.'/status', ['is_active' => false], $occupied->json('data.revision'))->assertConflict();
        $this->api('GET', '/tables?is_occupied=true')->assertOk()->assertJsonCount(1, 'data.tables');
        $this->api('GET', '?type=table&state=open&table_id='.$table->id.'&created_by='.$this->admin.'&date='.gmdate('Y-m-d').'&order=asc&per_page=10')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonMissingPath('data.sales.0.cash_session_id');
        $this->api('GET', '?date=2000-01-01')->assertOk()->assertJsonPath('meta.total', 0);
        foreach (['order=drop', 'date=2026-02-30', 'per_page=500', 'table_id=bad', 'state=paid'] as $query) {
            $this->api('GET', '?'.$query)->assertUnprocessable();
        }
        $this->api('POST', '', ['type' => 'table', 'table_id' => $table->id], key: 'occupied-table-key-01')->assertConflict();
    }

    public function test_permissions_revoked_session_token_and_inactive_user(): void
    {
        Auth::forgetGuards();
        $this->getJson('/api/v1/sales')->assertUnauthorized();
        $users = app(UserAdministration::class);
        $employee = $users->create($this->admin, ['name' => 'Sin permisos', 'username' => 'sales.employee', 'password' => 'EmployeeInitial!123'])->id;
        app(IdentityAccess::class)->changePassword($employee, 'EmployeeInitial!123', 'EmployeeChanged!123');
        $token = $this->login('sales.employee', 'EmployeeChanged!123');
        $this->api('GET', '', token: $token)->assertForbidden();
        $users->assignRole($this->admin, $employee, 'waiter');
        $this->api('GET', '', token: $token)->assertOk();
        $this->api('POST', '/tables', ['name' => 'No autorizado'], token: $token)->assertForbidden();
        $this->api('POST', '/'.strtolower((string) Str::ulid()).'/cancel', ['reason_code' => 'TEST_APPROVED'], token: $token)->assertForbidden();
        AccessSession::query()->where('user_id', $employee)->update(['revoked_at' => now()]);
        $this->api('GET', '', token: $token)->assertUnauthorized();
        $token = $this->login('sales.employee', 'EmployeeChanged!123');
        Auth::forgetGuards();
        $this->postJson('/api/v1/auth/logout', [], ['Authorization' => 'Bearer '.$token])->assertOk();
        $this->api('GET', '', token: $token)->assertUnauthorized();
        $token = $this->login('sales.employee', 'EmployeeChanged!123');
        $users->setActive($this->admin, $employee, false);
        $this->api('GET', '', token: $token)->assertUnauthorized();
    }

    public function test_inactive_product_foreign_item_and_closed_sale_do_not_mutate(): void
    {
        $this->fixtures();
        $created = $this->open();
        $id = $created->json('data.id');
        $added = $this->api('POST', '/'.$id.'/items', ['product_id' => $this->product, 'quantity' => 1], $created->json('data.revision'), 'horizontal-add-key01')->assertOk();
        $other = $this->open();
        $otherId = $other->json('data.id');
        $this->api('PATCH', '/'.$otherId.'/items/'.$added->json('data.items.0.id').'/quantity', ['quantity' => 2], $other->json('data.revision'))->assertNotFound();
        $catalog = app(CatalogAdministration::class);
        $catalog->setProductActive($this->admin, $this->product, false);
        $this->api('POST', '/'.$otherId.'/items', ['product_id' => $this->product, 'quantity' => 1], $other->json('data.revision'), 'inactive-add-key001')->assertConflict();
        $this->database->table('sales_sales')->where('id', $id)->update(['status' => 'closed', 'closed_at' => now()->utc(), 'folio' => '202611130001']);
        $closed = $this->api('GET', '/'.$id)->assertOk();
        foreach (['pause', 'resume', 'cancel'] as $action) {
            if ($action === 'cancel') {
                $this->app->instance(CancellationApproval::class, new TestCancellationApproval);
            }
            $this->api('POST', '/'.$id.'/'.$action, $action === 'cancel' ? ['reason_code' => 'TEST_APPROVED'] : [], $closed->json('data.revision'))->assertConflict();
        }
        $this->assertSame('10.1234', $this->sales->sale($this->admin, $id)->sale->total);
        $this->assertSame(1, $this->database->table('sales_items')->count());
    }

    public function test_unhandled_errors_never_publish_internal_details_in_either_debug_mode(): void
    {
        $this->fixtures();
        $this->app->instance(ActiveCashSession::class, new class implements ActiveCashSession
        {
            public function requireActive(string $actorId, ?string $expectedSessionId = null): string
            {
                throw new \RuntimeException('PRIVATE_SQL SELECT password C:\\private\\server.php cash_session_id=SECRET');
            }
        });
        foreach ([true, false] as $debug) {
            config(['app.debug' => $debug]);
            $response = $this->api('POST', '', ['type' => 'counter'], key: $debug ? 'debug-active-key-001' : 'debug-disabled-key01')->assertStatus(500)->assertJsonPath('code', 'INTERNAL_ERROR');
            foreach (['PRIVATE_SQL', 'password', 'server.php', 'SECRET', 'trace', 'cash_session_id'] as $secret) {
                $response->assertDontSee($secret);
            }
        }
        $this->assertSame(0, $this->database->table('sales_sales')->count());
    }

    public function test_bearer_actor_is_not_inherited_from_web_pin_context(): void
    {
        $this->fixtures();
        $users = app(UserAdministration::class);
        $employee = $users->create($this->admin, ['name' => 'Empleado PIN', 'username' => 'pin.sales', 'password' => 'EmployeeInitial!123'])->id;
        $users->assignRole($this->admin, $employee, 'waiter');
        app(IdentityAccess::class)->changePassword($employee, 'EmployeeInitial!123', 'EmployeeChanged!123');
        $token = $this->login('pin.sales', 'EmployeeChanged!123');
        $cookies = [];
        $web = function (string $path, array $data) use (&$cookies): TestResponse {
            Auth::forgetGuards();
            Auth::shouldUse('web');
            $response = $this->call('POST', $path, $data, $cookies, [], ['HTTP_ACCEPT' => 'text/html']);
            foreach ($response->headers->getCookies() as $cookie) {
                $cookies[$cookie->getName()] = $cookie->getValue();
            }

            return $response;
        };
        $web('/login', ['username' => 'pin.sales', 'password' => 'EmployeeChanged!123'])->assertRedirect();
        $web('/auth/pin', ['current_password' => 'EmployeeChanged!123', 'pin' => '0042', 'pin_confirmation' => '0042'])->assertRedirect();
        $web('/login', ['username' => 'sales.http', 'password' => 'SalesChanged!123'])->assertRedirect();
        $web('/auth/terminal', ['current_password' => 'SalesChanged!123'])->assertRedirect();
        $web('/auth/employee', ['username' => 'pin.sales', 'pin' => '0042'])->assertRedirect(route('auth.account'));
        Auth::forgetGuards();
        $this->call('GET', '/api/v1/sales', [], $cookies, [], ['HTTP_ACCEPT' => 'application/json'])->assertUnauthorized();
        $this->api('POST', '/tables', ['name' => 'Privilegio ajeno'], token: $token)->assertForbidden();
        $created = $this->api('POST', '', ['type' => 'counter'], key: 'real-employee-key001', token: $token)->assertCreated()->assertJsonPath('data.created_by', $employee);
        $this->assertSame($employee, $this->database->table('audit_security_events')->where('event_type', 'sales.opened')->where('record_id', $created->json('data.id'))->value('actor_id'));
        $this->api('POST', '', ['type' => 'counter', 'user_id' => $this->admin], key: 'forged-employee-key1', token: $token)->assertUnprocessable();
    }
}
