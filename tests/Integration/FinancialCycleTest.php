<?php

namespace Tests\Integration;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Cash\Contracts\CashClosings;
use Modules\Cash\Contracts\CashMovements;
use Modules\Cash\Contracts\CashOperations;
use Modules\Catalog\Contracts\CatalogAdministration;
use Modules\Core\Contracts\ApiCode;
use Modules\Core\Contracts\IdempotentOperations;
use Modules\Core\Contracts\OperationIntent;
use Modules\Core\Contracts\OperationRejected;
use Modules\Core\Infrastructure\Persistence\SqlOperationStore;
use Modules\Sales\Application\Services\DurableSalesWrites;
use Modules\Sales\Contracts\CriticalSalesWrites;
use Modules\Users\Contracts\IdentityAccess;
use Modules\Users\Contracts\UserAdministration;
use Tests\Support\FinancialIntegrity;
use Tests\Support\ReconciliationSqlServerTestCase;

class FinancialCycleTest extends ReconciliationSqlServerTestCase
{
    private string $token;

    private static array $samples = [];

    protected function setUp(): void
    {
        parent::setUp();
        // El soporte histórico deshabilita estas escrituras: este recorrido usa el adaptador real.
        $this->app->bind(CriticalSalesWrites::class, DurableSalesWrites::class);
        Auth::forgetGuards();
        $response = $this->postJson('/api/v1/auth/login', ['username' => 'finance.fixture', 'password' => 'FinanceChanged!123'])->assertOk();
        $this->token = $response->json('data.token');
    }

    public static function tearDownAfterClass(): void
    {
        file_put_contents(dirname(__DIR__, 2).'/.tools/acceptance-cycle-responses.json', json_encode(self::$samples, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        self::$samples = [];
        parent::tearDownAfterClass();
    }

    private function api(string $method, string $path, array $data = [], ?string $key = null, ?string $revision = null): TestResponse
    {
        Auth::forgetGuards();
        foreach (app('router')->getRoutes() as $route) {
            $route->flushController();
        }
        $headers = ['Authorization' => 'Bearer '.$this->token, 'X-Request-Id' => 'financial-cycle',
            ...($key === null ? [] : ['Idempotency-Key' => $key]), ...($revision === null ? [] : ['If-Match' => '"'.$revision.'"'])];
        $response = $this->json($method, '/api/v1'.$path, $data, $headers);
        $response->assertHeader('X-Request-Id', 'financial-cycle')->assertJsonPath('meta.request_id', 'financial-cycle');
        $route = app('router')->getRoutes()->match(Request::create('/api/v1'.$path, $method));
        self::$samples[] = ['method' => strtolower($method), 'path' => substr($route->uri(), strlen('api/v1')), 'status' => $response->status(), 'body' => $response->json()];

        return $response;
    }

    public function test_http_cycle_reconciles_every_payment_method_then_isolates_a_second_turn(): void
    {
        // Terminar exclusivamente la apertura de fixture para probar una apertura HTTP desde cero.
        $this->closeCount();
        $this->api('GET', '/cash/sessions/current')->assertOk()->assertJsonPath('data.session', null);
        $register = $this->api('GET', '/cash/registers')->assertOk()->json('data.registers.0.id');
        $openingKey = (string) Str::uuid();
        $opening = ['register_id' => $register, 'opening_fund' => '123.4567'];
        $opened = $this->api('POST', '/cash/sessions', $opening, $openingKey)->assertOk()->json('data.session');
        $sessionId = $opened['id'];
        $this->assertTrue(Str::isUlid($sessionId));
        $this->assertSame($this->actor, $opened['opened_by']);
        $this->assertStringEndsWith('Z', $opened['opened_at']);
        $this->assertSame($opened, $this->api('POST', '/cash/sessions', $opening, $openingKey)->assertOk()->json('data.session'));
        $this->api('POST', '/cash/sessions', $opening, (string) Str::uuid())->assertConflict();

        $sales = [];
        foreach (['cash', 'card', 'transfer', 'other', 'mixed'] as $method) {
            $table = in_array($method, ['card', 'mixed'], true)
                ? $this->api('POST', '/sales/tables', ['name' => 'Cycle '.$method])->assertCreated()->json('data.id') : null;
            $body = ['type' => $table ? 'table' : 'counter', ...($table ? ['table_id' => $table] : [])];
            $key = (string) Str::uuid();
            $sale = $this->api('POST', '/sales', $body, $key)->assertCreated()->json('data');
            $this->assertSame($sale, $this->api('POST', '/sales', $body, $key)->assertCreated()->json('data'));
            $sale = $this->api('POST', '/sales/'.$sale['id'].'/items', ['product_id' => $this->productId, 'quantity' => 1, 'note' => 'Historical cycle'], (string) Str::uuid(), $sale['revision'])->assertOk()->json('data');
            if ($method === 'cash') {
                $sale = $this->api('PATCH', '/sales/'.$sale['id'].'/items/'.$sale['items'][0]['id'].'/quantity', ['quantity' => 2], (string) Str::uuid(), $sale['revision'])->assertOk()->json('data');
                $sale = $this->api('PATCH', '/sales/'.$sale['id'].'/note', ['note' => 'Recover this note'], (string) Str::uuid(), $sale['revision'])->assertOk()->json('data');
                $sale = $this->api('POST', '/sales/'.$sale['id'].'/pause', [], (string) Str::uuid(), $sale['revision'])->assertOk()->assertJsonPath('data.state', 'paused')->json('data');
                $sale = $this->api('POST', '/sales/'.$sale['id'].'/resume', [], (string) Str::uuid(), $sale['revision'])->assertOk()->assertJsonPath('data.note', 'Recover this note')->json('data');
            }
            $sales[$method] = $sale;
        }
        $this->assertSame(5, $this->database->table('sales_sales')->where('cash_session_id', $sessionId)->count());
        $this->api('GET', '/cash/sessions/'.$sessionId.'/summary')->assertConflict()->assertJsonMissingPath('data.expected_cash');
        $this->api('POST', '/cash/sessions/'.$sessionId.'/close', ['counted_cash' => '0', 'observation' => 'Pending sales'], (string) Str::uuid())->assertConflict();

        $catalog = app(CatalogAdministration::class);
        $category = $catalog->createCategory($this->actor, ['name' => 'Later catalog category']);
        $catalog->updateProduct($this->actor, $this->productId, ['name' => 'Later product name', 'category_id' => $category->id, 'price' => '999']);
        $catalog->setProductActive($this->actor, $this->productId, false);
        $catalog->deleteProduct($this->actor, $this->productId);

        $folios = [];
        foreach ($sales as $method => $sale) {
            $payments = $method === 'mixed' ? [$this->payment('cash', '80', '100'), $this->payment('card', '100')]
                : [$this->payment($method, $method === 'cash' ? '360' : '180', '400')];
            $key = (string) Str::uuid();
            $closed = $this->api('POST', '/sales/'.$sale['id'].'/checkout', ['payments' => $payments], $key, $sale['revision'])->assertOk()->json('data');
            $this->assertSame($closed, $this->api('POST', '/sales/'.$sale['id'].'/checkout', ['payments' => $payments], $key, $sale['revision'])->assertOk()->json('data'));
            $this->assertSame('Checkout product', $closed['items'][0]['name']);
            $this->assertSame('180.0000', $closed['items'][0]['unit_price']);
            $this->assertSame('0.0000', $closed['discount']);
            $folios[] = $closed['folio'];
            if ($sale['table_id']) {
                $this->api('GET', '/sales/tables/'.$sale['table_id'])->assertOk()->assertJsonPath('data.availability', 'free')->assertJsonPath('data.active_sale_id', null);
            }
            $this->api('GET', '/operations/'.$key.'/status')->assertOk()->assertJsonPath('data.operation.state', 'completed');
        }
        $this->assertSame(['202611170001', '202611170002', '202611170003', '202611170004', '202611170005'], $folios);
        $this->assertSame(6, $this->database->table('sales_payments')->where('status', 'confirmed')->count());

        foreach ([['cash_in', '30.1234'], ['cash_out', '10.0001']] as [$type, $amount]) {
            $key = (string) Str::uuid();
            $body = ['cash_session_id' => $sessionId, 'type' => $type, 'amount' => $amount, 'concept' => 'Cycle authorized movement'];
            $movement = $this->api('POST', '/cash/movements', $body, $key)->assertCreated()->json('data');
            $this->assertSame($movement, $this->api('POST', '/cash/movements', $body, $key)->assertCreated()->json('data'));
        }
        $balance = $this->api('GET', '/cash/movements')->assertOk()->json('data.balance');
        $persisted = $this->database->selectOne("SELECT CAST(SUM(subtotal) AS varchar(40)) AS subtotal, CAST(SUM(discount_amount) AS varchar(40)) AS discount, CAST(SUM(total) AS varchar(40)) AS total FROM sales_sales WHERE cash_session_id=? AND status='closed'", [$sessionId]);
        $this->assertSame('1080.0000', $persisted->subtotal);
        $this->assertSame('0.0000', $persisted->discount);
        $this->assertSame('1080.0000', $persisted->total);
        $cash = $this->database->selectOne("SELECT CAST(SUM(applied_amount) AS varchar(40)) AS applied, CAST(SUM(received_cash) AS varchar(40)) AS received, CAST(SUM(change_cash) AS varchar(40)) AS change_cash FROM sales_payments WHERE cash_session_id=? AND method_code='cash' AND status='confirmed'", [$sessionId]);
        $this->assertSame('440.0000', $cash->applied);
        $this->assertSame('500.0000', $cash->received);
        $this->assertSame('60.0000', $cash->change_cash);
        $this->assertSame('583.5800', $balance['expected_cash']);
        $this->assertSame(2, $this->database->table('cash_movements')->count());

        $key = (string) Str::uuid();
        $body = ['counted_cash' => '583.5800', 'observation' => 'Full persisted reconciliation'];
        $summary = $this->api('POST', '/cash/sessions/'.$sessionId.'/close', $body, $key)->assertOk()->json('data.reconciliation');
        $this->assertSame($summary, $this->api('POST', '/cash/sessions/'.$sessionId.'/close', $body, $key)->assertOk()->json('data.reconciliation'));
        $this->assertSame('0.0000', $summary['difference']);
        $this->assertStringEndsWith('Z', $summary['closed_at']);
        $this->assertSame('1080.0000', $summary['total_sold']);
        $this->assertSame(5, $summary['closed_sales']);
        $this->assertSame(['card' => '280.0000', 'cash' => '440.0000', 'other' => '180.0000', 'transfer' => '180.0000'], array_column($summary['payment_methods'], 'applied_amount', 'method_code'));
        $this->assertSame(5, $this->database->table('audit_security_events')->where('event_type', 'sales.checkout.confirmed')->count());
        $this->assertSame(2, $this->database->table('audit_security_events')->where('event_type', 'cash.session.closed')->count());

        $new = $this->api('POST', '/cash/sessions', ['register_id' => $register, 'opening_fund' => '0'], (string) Str::uuid())->assertOk()->json('data.session');
        $this->assertNotSame($sessionId, $new['id']);
        $this->api('GET', '/cash/movements')->assertOk()->assertJsonCount(0, 'data.movements')->assertJsonPath('data.balance.expected_cash', '0.0000');
        // Nuevo producto de fixture: el producto histórico ya fue eliminado del catálogo.
        $this->productId = $catalog->createProduct($this->actor, ['name' => 'Second turn product', 'category_id' => $category->id, 'price' => '180'])->id;
        $sale = $this->sale();
        $this->assertSame($new['id'], $sale->sale->cashSessionId);
        $this->api('POST', '/sales/'.$sale->sale->id.'/checkout', ['payments' => [$this->payment('card')]], (string) Str::uuid(), trim($this->revision($sale), '"'))->assertOk()->assertJsonPath('data.folio', '202611170006');
        $second = $this->api('POST', '/cash/sessions/'.$new['id'].'/close', ['counted_cash' => '0', 'observation' => 'No cash transferred'], (string) Str::uuid())->assertOk()->json('data.reconciliation');
        $this->assertNotSame($summary['id'], $second['id']);
        $this->assertSame('0.0000', $second['expected_cash']);
        $this->assertSame('180.0000', $second['total_sold']);
        $this->assertSame($summary, $this->api('GET', '/cash/sessions/'.$sessionId.'/reconciliation')->assertOk()->json('data.reconciliation'));
        $this->assertSame(0, $this->database->table('idempotency_records')->where('state', 'pending')->count());
        $this->assertSame(0, $this->database->table('cash_sessions')->where('status', 'open')->count());
        $integrity = FinancialIntegrity::inspect($this->database);
        $this->assertTrue($integrity['passed'], json_encode($integrity, JSON_THROW_ON_ERROR));
        file_put_contents(base_path('.tools/acceptance-cycle-integrity.json'), json_encode($integrity, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    public function test_compaction_preserves_financial_history_fresh_receipts_and_uncertain_intentions(): void
    {
        $key = (string) Str::uuid();
        $summary = $this->closeCount('100', $key);
        $pending = new OperationIntent(OperationIntent::context($this->actor), (string) Str::uuid(), 'probe.pending', 'POST', null, []);
        $this->database->transaction(fn () => app(SqlOperationStore::class)->pending($pending));
        $rowCount = $this->database->table('idempotency_records')->count();
        $this->travel(25)->hours();
        // Un movimiento posterior a la expiración debe conservar su respuesta vigente.
        $register = app(CashOperations::class)->registers($this->actor)[0];
        $session = app(CashOperations::class)->open($this->actor, $register->id, '0', (string) Str::uuid());
        $fresh = app(CashMovements::class)->record($this->actor, $session->id, 'cash_in', '0.0001', 'Fresh receipt', (string) Str::uuid());
        $this->assertSame(0, Artisan::call('operations:compact'));
        $this->assertStringContainsString('Respuestas expiradas retiradas:', Artisan::output());
        $this->assertSame($rowCount + 1, $this->database->table('idempotency_records')->count());
        $this->assertNull($this->database->table('idempotency_records')->where('idempotency_key', $key)->value('response'));
        $this->assertNotNull($this->database->table('idempotency_records')->where('resource_id', $fresh->id)->value('response'));
        $this->assertSame('pending', app(IdempotentOperations::class)->status($pending->ownerContext, $pending->key)->state);
        $this->assertSame($summary, app(CashClosings::class)->reconciliation($this->actor, $this->sessionId));
        try {
            $this->closeCount('100', $key);
            $this->fail('An expired financial key must never execute again.');
        } catch (OperationRejected $exception) {
            $this->assertSame(ApiCode::IDEMPOTENCY_KEY_CONFLICT, $exception->apiCode);
        }
        $this->assertSame(1, $this->database->table('cash_reconciliations')->count());
        $this->assertSame(0, Artisan::call('schedule:list'));
        $this->assertStringContainsString('operations:compact', Artisan::output());
    }

    public function test_api_access_revocation_and_password_change_cover_the_auth_contracts(): void
    {
        $this->api('GET', '/ping')->assertOk()->assertJsonPath('data.status', 'ok');
        $this->api('GET', '/auth/me')->assertOk();
        $adminToken = $this->token;
        $users = app(UserAdministration::class);
        $employee = $users->create($this->actor, ['name' => 'Cycle cashier', 'username' => 'cycle.cashier', 'password' => 'CycleInitial!123']);
        $users->assignRole($this->actor, $employee->id, 'cashier');
        app(IdentityAccess::class)->changePassword($employee->id, 'CycleInitial!123', 'CycleChanged!123');
        $login = ['username' => 'cycle.cashier', 'password' => 'CycleChanged!123'];
        $employeeToken = $this->api('POST', '/auth/login', $login)->assertOk()->json('data.token');
        $sessions = $this->api('GET', '/auth/users/'.$employee->id.'/sessions')->assertOk()->json('data.sessions');
        $this->assertCount(1, $sessions);
        $this->api('DELETE', '/auth/sessions/'.$sessions[0]['id'])->assertOk();
        $this->token = $employeeToken;
        $this->api('GET', '/cash/sessions/current')->assertUnauthorized();
        $this->token = $adminToken;
        $employeeToken = $this->api('POST', '/auth/login', $login)->assertOk()->json('data.token');
        $this->api('DELETE', '/auth/users/'.$employee->id.'/sessions')->assertOk();
        $this->token = $employeeToken;
        $this->api('POST', '/cash/sessions/'.$this->sessionId.'/close', ['counted_cash' => '100', 'observation' => 'Revoked actor'], (string) Str::uuid())->assertUnauthorized();
        $this->token = $adminToken;
        $this->api('GET', '/auth/sessions')->assertOk();
        $this->api('DELETE', '/auth/sessions', ['others' => true])->assertOk();
        $this->api('POST', '/auth/password', ['current_password' => 'FinanceChanged!123', 'password' => 'FinanceRenewed!456', 'password_confirmation' => 'FinanceRenewed!456'])->assertOk();
        $this->api('GET', '/cash/sessions/current')->assertUnauthorized();
        $this->token = $this->api('POST', '/auth/login', ['username' => 'finance.fixture', 'password' => 'FinanceRenewed!456'])->assertOk()->json('data.token');
        $this->api('POST', '/auth/logout')->assertOk();
        $this->api('GET', '/auth/me')->assertUnauthorized();
        $this->assertSame('open', $this->database->table('cash_sessions')->where('id', $this->sessionId)->value('status'));
        $this->assertSame(0, $this->database->table('cash_reconciliations')->count());
    }
}
