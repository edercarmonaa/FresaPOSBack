<?php

namespace Tests\Integration;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Core\Contracts\SecurityEvent;
use Modules\Sales\Contracts\SalesOperations;
use Modules\Users\Contracts\IdentityAccess;
use Modules\Users\Contracts\UserAdministration;
use Tests\Support\CheckoutSqlServerTestCase;

class CheckoutHttpTest extends CheckoutSqlServerTestCase
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
        file_put_contents(dirname(__DIR__, 2).'/.tools/checkout-1.5d3-responses.json', json_encode(self::$samples, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        self::$samples = [];
        parent::tearDownAfterClass();
    }

    private function checkout(string $id, array $payload, ?string $key, ?string $revision, ?string $token = null): TestResponse
    {
        Auth::forgetGuards();
        foreach (app('router')->getRoutes() as $route) {
            $route->flushController();
        }
        $response = $this->postJson('/api/v1/sales/'.$id.'/checkout', $payload, ['Authorization' => 'Bearer '.($token ?? $this->token), 'X-Request-Id' => 'checkout-test', ...($key === null ? [] : ['Idempotency-Key' => $key]), ...($revision === null ? [] : ['If-Match' => $revision])]);
        $response->assertHeader('X-Request-Id', 'checkout-test')->assertJsonPath('meta.request_id', 'checkout-test');
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::$samples[] = ['method' => 'post', 'path' => '/sales/{id}/checkout', 'status' => $response->status(), 'body' => $response->json()];

        return $response;
    }

    public function test_checkout_api_receipt_replay_history_folio_and_strict_client_authority(): void
    {
        $sale = $this->sale();
        $id = $sale->sale->id;
        $key = (string) Str::uuid();
        $rev = $this->revision($sale);
        $payload = ['payments' => [$this->payment()]];
        $this->checkout($id, $payload, null, $rev)->assertStatus(400);
        $this->checkout($id, $payload, (string) Str::uuid(), null)->assertConflict();
        foreach (['total' => '1', 'folio' => '202611170001', 'closed_at' => '2026-11-17', 'created_by' => $this->actor, 'state' => 'closed'] as $field => $value) {
            $this->checkout($id, $payload + [$field => $value], (string) Str::uuid(), $rev)->assertUnprocessable();
        }
        $a = $this->checkout($id, $payload, $key, $rev)->assertOk()->assertJsonPath('data.state', 'closed')->assertJsonPath('data.payments.0.change_cash', '20.0000');
        $b = $this->checkout($id, $payload, $key, $rev)->assertOk();
        $this->assertSame($a->json('data'), $b->json('data'));
        $this->checkout($id, ['payments' => [$this->payment('card')]], $key, $rev)->assertConflict()->assertJsonPath('code', 'IDEMPOTENCY_KEY_CONFLICT');
        $this->checkout($id, $payload, (string) Str::uuid(), $rev)->assertConflict();
        Auth::forgetGuards();
        $this->getJson('/api/v1/sales/'.$id, ['Authorization' => 'Bearer '.$this->token])->assertOk()->assertJsonPath('data.folio', $a->json('data.folio'))->assertJsonCount(1, 'data.payments');
        Auth::forgetGuards();
        $this->getJson('/api/v1/sales?folio='.$a->json('data.folio'), ['Authorization' => 'Bearer '.$this->token])->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.sales.0.folio', $a->json('data.folio'));
        $this->assertSame(1, $this->database->table('sales_payments')->count());
    }

    public function test_invalid_amounts_methods_nested_fields_and_missing_sale_are_safe(): void
    {
        $sale = $this->sale();
        foreach ([['applied_amount' => 180], ['applied_amount' => '1e2'], ['applied_amount' => '180.00001'], ['applied_amount' => '-1'], ['payment_method_id' => strtolower((string) Str::ulid())], ['received_cash' => '1'], ['change_cash' => '1'], ['reference' => '4111-1111-1111-1111']] as $change) {
            $this->checkout($sale->sale->id, ['payments' => [array_replace($this->payment(), $change)]], (string) Str::uuid(), $this->revision($sale))->assertUnprocessable();
        }
        $this->checkout(strtolower((string) Str::ulid()), ['payments' => [$this->payment()]], (string) Str::uuid(), $this->revision($sale))->assertNotFound();
        $this->assertSame(0, $this->database->table('sales_payments')->count());
    }

    public function test_current_permissions_actor_access_and_other_authorization_are_enforced(): void
    {
        $sale = $this->sale();
        $users = app(UserAdministration::class);
        foreach (['waiter', 'cashier'] as $role) {
            $user = $users->create($this->actor, ['name' => 'Checkout '.$role, 'username' => 'checkout.'.$role, 'password' => 'EmployeeInitial!123']);
            $users->assignRole($this->actor, $user->id, $role);
            app(IdentityAccess::class)->changePassword($user->id, 'EmployeeInitial!123', 'EmployeeChanged!123');
            Auth::forgetGuards();
            $token = $this->postJson('/api/v1/auth/login', ['username' => 'checkout.'.$role, 'password' => 'EmployeeChanged!123'])->assertOk()->json('data.token');
            $this->checkout($sale->sale->id, ['payments' => [$this->payment('other')]], (string) Str::uuid(), $this->revision($sale), $token)->assertStatus($role === 'waiter' ? 403 : 422);
            if ($role === 'cashier') {
                $this->checkout($sale->sale->id, ['payments' => [$this->payment('card')]], (string) Str::uuid(), $this->revision($sale), $token)->assertOk()->assertJsonPath('data.closed_by', $user->id);
                $users->setActive($this->actor, $user->id, false);
                $this->checkout($sale->sale->id, ['payments' => [$this->payment('card')]], (string) Str::uuid(), $this->revision($sale), $token)->assertUnauthorized();
            }
        }
    }

    public function test_debug_modes_hide_internal_failure_and_rollback_every_financial_effect(): void
    {
        $sale = $this->sale();
        Event::listen(SecurityEvent::class, function (SecurityEvent $e): void {
            if ($e->type === 'sales.checkout.confirmed') {
                throw new \RuntimeException('Secret SQL C:\\private\\server password');
            }
        });
        foreach ([true, false] as $debug) {
            config(['app.debug' => $debug]);
            $r = $this->checkout($sale->sale->id, ['payments' => [$this->payment()]], (string) Str::uuid(), $this->revision($sale))->assertStatus(500)->assertJsonPath('code', 'INTERNAL_ERROR');
            $this->assertStringNotContainsString('private', $r->getContent());
            $this->assertStringNotContainsString('password', $r->getContent());
            $this->assertSame(0, $this->database->table('sales_payments')->count());
            $this->assertSame(0, $this->database->table('sales_daily_sequences')->count());
            $this->assertSame('open', app(SalesOperations::class)->sale($this->actor, $sale->sale->id)->sale->state->value);
        }
    }
}
