<?php

namespace Tests\Integration;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Auth\Infrastructure\Models\AccessSession;
use Modules\Cash\Contracts\CashMovements;
use Modules\Cash\Contracts\CashOperations;
use Modules\Cash\Contracts\PaymentMethods;
use Modules\Core\Contracts\SecurityEvent;
use Modules\Users\Contracts\IdentityAccess;
use Modules\Users\Contracts\UserAdministration;
use Tests\Support\FinanceSqlServerTestCase;

class CashFinanceHttpTest extends FinanceSqlServerTestCase
{
    private string $token;

    private static array $samples = [];

    protected function setUp(): void
    {
        parent::setUp();
        Auth::forgetGuards();
        $this->token = $this->postJson('/api/v1/auth/login', ['username' => 'finance.fixture', 'password' => 'FinanceChanged!123'])->assertOk()->json('data.token');
    }

    public static function tearDownAfterClass(): void
    {
        file_put_contents(dirname(__DIR__, 2).'/.tools/finance-1.5d2b-contract-responses.json', json_encode(self::$samples, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        self::$samples = [];
        parent::tearDownAfterClass();
    }

    private function api(string $method, string $path, array $data = [], ?string $key = null, ?string $token = null): TestResponse
    {
        Auth::forgetGuards();
        foreach (app('router')->getRoutes() as $route) {
            $route->flushController();
        }
        $response = $this->json($method, '/api/v1/cash'.$path, $data, ['Authorization' => 'Bearer '.($token ?? $this->token), 'X-Request-Id' => 'finance-test', ...($key === null ? [] : ['Idempotency-Key' => $key])]);
        $response->assertHeader('X-Request-Id', 'finance-test')->assertJsonPath('meta.request_id', 'finance-test');
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        if ($method === 'GET' || $path === '/movements') {
            $documented = preg_replace('#(/(?:payment-methods|movements))/[^/]+$#', '$1/{id}', '/cash'.$path);
            self::$samples[] = ['method' => strtolower($method), 'path' => $documented, 'status' => $response->status(), 'body' => $response->json()];
        }

        return $response;
    }

    public function test_methods_active_identity_read_only_and_no_payment_confirmation_route(): void
    {
        $list = $this->api('GET', '/payment-methods')->assertOk()->assertJsonCount(4, 'data.payment_methods')->json('data.payment_methods');
        $this->api('GET', '/payment-methods/'.$list[0]['id'])->assertOk()->assertJsonPath('data.payment_method.code', 'cash');
        app(PaymentMethods::class)->setActive($this->actor, $list[0]['id'], false);
        $this->api('GET', '/payment-methods')->assertOk()->assertJsonCount(3, 'data.payment_methods');
        $this->api('GET', '/payment-methods/'.$list[0]['id'])->assertNotFound();
        $this->api('GET', '/payment-methods/not-an-id')->assertNotFound();
        $this->api('POST', '/payment-methods', ['code' => 'arbitrary'])->assertStatus(405);
        $this->postJson('/api/v1/sales/'.strtolower((string) Str::ulid()).'/payments', [], ['Authorization' => 'Bearer '.$this->token])->assertNotFound();
        $this->assertSame(0, $this->database->table('sales_payments')->count());
    }

    public function test_record_replay_conflict_filters_pagination_and_exact_balance(): void
    {
        $payload = ['cash_session_id' => $this->sessionId, 'type' => 'cash_in', 'amount' => '12.50', 'concept' => 'Reposición autorizada'];
        $key = (string) Str::uuid();
        $a = $this->api('POST', '/movements', $payload, $key)->assertCreated()->assertJsonPath('data.movement.amount', '12.5000');
        $b = $this->api('POST', '/movements', array_replace($payload, ['amount' => '12.5000']), $key)->assertCreated();
        $this->assertSame($a->json('data'), $b->json('data'));
        $this->api('POST', '/movements', array_replace($payload, ['amount' => '13']), $key)->assertConflict()->assertJsonPath('code', 'IDEMPOTENCY_KEY_CONFLICT');
        $id = $a->json('data.movement.id');
        $this->api('GET', '/movements/'.$id)->assertOk()->assertJsonPath('data.movement.concept', $payload['concept']);
        for ($i = 0; $i < 11; $i++) {
            app(CashMovements::class)->record($this->actor, $this->sessionId, 'cash_in', '0.0001', 'Paginar '.($i + 1), (string) Str::uuid());
        }
        $this->api('GET', '/movements', ['type' => 'cash_in', 'cash_session_id' => $this->sessionId, 'date' => gmdate('Y-m-d'), 'created_by' => $this->actor, 'page' => 2, 'per_page' => 10])->assertOk()->assertJsonCount(2, 'data.movements')->assertJsonPath('meta.total', 12)->assertJsonPath('meta.page', 2)->assertJsonPath('data.balance.expected_cash', '112.5011');
        $this->api('GET', '/movements', ['type' => 'cash_out'])->assertOk()->assertJsonCount(0, 'data.movements');
        $this->api('GET', '/movements/'.strtolower((string) Str::ulid()))->assertNotFound();
        $this->assertSame(12, $this->database->table('cash_movements')->count());
        $this->assertSame(12, $this->database->table('audit_security_events')->where('event_type', 'cash.movement.recorded')->count());
    }

    public function test_invalid_headers_values_identity_and_unknown_fields_never_write(): void
    {
        $payload = ['cash_session_id' => $this->sessionId, 'type' => 'cash_out', 'amount' => '1', 'concept' => 'Salida válida'];
        $this->api('POST', '/movements', $payload)->assertStatus(400);
        $this->api('POST', '/movements', $payload, 'not-uuid')->assertStatus(400);
        foreach ([['amount' => '0'], ['amount' => '-1'], ['amount' => 1.5], ['amount' => '1e2'], ['amount' => '1.00001'], ['concept' => ' '], ['type' => 'sale_income'], ['created_by' => $this->actor], ['expected_cash' => '99999'], ['change' => '10']] as $change) {
            $this->api('POST', '/movements', array_replace($payload, $change), (string) Str::uuid())->assertUnprocessable();
        }
        $this->api('POST', '/movements', array_replace($payload, ['amount' => '100.0001']), (string) Str::uuid())->assertConflict()->assertJsonPath('code', 'CONFLICT');
        $this->api('GET', '/movements', ['per_page' => 15])->assertUnprocessable();
        $this->api('GET', '/movements', ['date' => '2026-02-30'])->assertUnprocessable();
        $this->assertSame(0, $this->database->table('cash_movements')->count());
        $this->assertSame('100.0000', $this->funds()['expected_cash']);
    }

    public function test_current_session_visibility_and_historical_access_are_permission_scoped(): void
    {
        $users = app(UserAdministration::class);
        $employee = $users->create($this->actor, ['name' => 'Cashier fixture', 'username' => 'finance.cashier', 'password' => 'EmployeeInitial!123']);
        $users->assignRole($this->actor, $employee->id, 'cashier');
        app(IdentityAccess::class)->changePassword($employee->id, 'EmployeeInitial!123', 'EmployeeChanged!123');
        Auth::forgetGuards();
        $token = $this->postJson('/api/v1/auth/login', ['username' => 'finance.cashier', 'password' => 'EmployeeChanged!123'])->assertOk()->json('data.token');
        $movement = app(CashMovements::class)->record($this->actor, $this->sessionId, 'cash_in', '5', 'Historial', (string) Str::uuid());
        $this->api('GET', '/movements', token: $token)->assertOk()->assertJsonCount(1, 'data.movements');
        $this->api('GET', '/movements', ['created_by' => $this->actor], token: $token)->assertForbidden();
        $old = $this->sessionId;
        $this->closeFixture();
        $register = app(CashOperations::class)->registers($this->actor)[0];
        app(CashOperations::class)->open($this->actor, $register->id, '0', 'finance-second-open-12345');
        $this->api('GET', '/movements/'.$movement->id, token: $token)->assertNotFound();
        $this->api('GET', '/movements', ['cash_session_id' => $old], token: $token)->assertNotFound();
        $this->api('GET', '/movements', ['cash_session_id' => $old])->assertOk()->assertJsonCount(1, 'data.movements')->assertJsonPath('data.balance.expected_cash', '105.0000');
        $this->api('POST', '/movements', ['cash_session_id' => $old, 'type' => 'cash_out', 'amount' => '1', 'concept' => 'Sesión anterior'], (string) Str::uuid(), $token)->assertConflict();
    }

    public function test_revoked_permissions_tokens_sessions_inactive_actor_and_waiter_are_denied(): void
    {
        $payload = ['cash_session_id' => $this->sessionId, 'type' => 'cash_in', 'amount' => '1', 'concept' => 'Autorizado'];
        $key = (string) Str::uuid();
        $this->api('POST', '/movements', $payload, $key)->assertCreated();
        $this->database->table('role_has_permissions')->where('permission_id', $this->database->table('permissions')->where('name', 'cash.movements.create')->value('id'))->delete();
        $this->api('POST', '/movements', $payload, $key)->assertForbidden();
        AccessSession::query()->where('user_id', $this->actor)->update(['revoked_at' => now()]);
        $this->api('GET', '/movements')->assertUnauthorized();
        Auth::forgetGuards();
        $token = $this->postJson('/api/v1/auth/login', ['username' => 'finance.fixture', 'password' => 'FinanceChanged!123'])->assertOk()->json('data.token');
        $this->database->table('personal_access_tokens')->where('token', hash('sha256', explode('|', $token)[1]))->delete();
        $this->api('GET', '/payment-methods', token: $token)->assertUnauthorized();
        Auth::forgetGuards();
        $token = $this->postJson('/api/v1/auth/login', ['username' => 'finance.fixture', 'password' => 'FinanceChanged!123'])->assertOk()->json('data.token');
        $this->database->table('users')->where('id', $this->actor)->update(['is_active' => false]);
        $this->api('GET', '/movements', token: $token)->assertUnauthorized();
        $this->assertSame(1, $this->database->table('cash_movements')->count());
    }

    public function test_internal_errors_are_sanitized_and_atomic_in_both_debug_modes(): void
    {
        $armed = true;
        app('events')->listen(SecurityEvent::class, function ($event) use (&$armed): void {
            if ($armed && $event->type === 'cash.movement.recorded') {
                throw new \RuntimeException('SQLSTATE password server C:/private/connection.php');
            }
        });
        foreach ([true, false] as $debug) {
            config(['app.debug' => $debug]);
            $response = $this->api('POST', '/movements', ['cash_session_id' => $this->sessionId, 'type' => 'cash_in', 'amount' => '1', 'concept' => 'Rollback'], (string) Str::uuid())->assertStatus(500);
            foreach (['SQLSTATE', 'password', 'connection.php', 'trace'] as $secret) {
                $this->assertStringNotContainsString($secret, $response->getContent());
            }
        }
        $this->assertSame(0, $this->database->table('cash_movements')->count());
        $this->assertSame(0, $this->database->table('idempotency_records')->count());
    }
}
