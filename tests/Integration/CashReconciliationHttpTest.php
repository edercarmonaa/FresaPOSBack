<?php

namespace Tests\Integration;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Core\Contracts\SecurityEvent;
use Modules\Users\Contracts\IdentityAccess;
use Modules\Users\Contracts\UserAdministration;
use Tests\Support\ReconciliationSqlServerTestCase;

class CashReconciliationHttpTest extends ReconciliationSqlServerTestCase
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
        file_put_contents(dirname(__DIR__, 2).'/.tools/reconciliation-responses.json', json_encode(self::$samples, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        self::$samples = [];
        parent::tearDownAfterClass();
    }

    private function api(string $action, array $data = [], ?string $key = null, ?string $token = null, ?string $id = null): TestResponse
    {
        Auth::forgetGuards();
        foreach (app('router')->getRoutes() as $route) {
            $route->flushController();
        }
        $path = $action === 'history' ? '/cash/sessions/history' : '/cash/sessions/{id}/'.$action;
        $uri = '/api/v1'.str_replace('{id}', $id ?? $this->sessionId, $path);
        $headers = ['Authorization' => 'Bearer '.($token ?? $this->token), 'X-Request-Id' => 'reconciliation-test', ...($key === null ? [] : ['Idempotency-Key' => $key])];
        $method = $action === 'close' ? 'post' : 'get';
        $response = $method === 'post' ? $this->postJson($uri, $data, $headers) : $this->getJson($uri.($data ? '?'.http_build_query($data) : ''), $headers);
        $response->assertHeader('X-Request-Id', 'reconciliation-test')->assertJsonPath('meta.request_id', 'reconciliation-test');
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::$samples[] = ['method' => $method, 'path' => $path, 'status' => $response->status(), 'body' => $response->json()];

        return $response;
    }

    public function test_blind_endpoints_strict_fields_closing_replay_and_history_contracts(): void
    {
        $this->api('pending-sales')->assertOk()->assertJsonCount(0, 'data.pending_sales');
        $this->api('summary')->assertConflict()->assertJsonMissingPath('data.expected_cash');
        $this->api('reconciliation')->assertConflict();
        $this->api('history')->assertOk()->assertJsonCount(0, 'data.closings');
        $body = ['counted_cash' => '99.9999', 'observation' => 'Conteo físico completo'];
        $this->api('close', $body)->assertStatus(400);
        foreach (['expected_cash' => '100', 'difference' => '0', 'closed_at' => '2026-11-18', 'closed_by' => $this->actor, 'status' => 'closed', 'register_id' => strtolower((string) Str::ulid())] as $field => $value) {
            $this->api('close', $body + [$field => $value], (string) Str::uuid())->assertUnprocessable();
        }
        foreach (['-1', 0, 1.0, '1.00001', '1e2'] as $value) {
            $this->api('close', array_replace($body, ['counted_cash' => $value]), (string) Str::uuid())->assertUnprocessable();
        }
        $this->api('close', ['counted_cash' => '100'], (string) Str::uuid())->assertUnprocessable();
        $key = (string) Str::uuid();
        $first = $this->api('close', $body, $key)->assertOk()->assertJsonPath('data.reconciliation.difference', '-0.0001')->assertJsonPath('data.reconciliation.status', 'closed');
        $this->assertSame($first->json('data'), $this->api('close', $body, $key)->assertOk()->json('data'));
        $this->api('close', array_replace($body, ['counted_cash' => '100']), $key)->assertConflict();
        $this->api('close', $body, (string) Str::uuid())->assertConflict();
        $this->api('summary')->assertOk()->assertJsonPath('data.reconciliation.expected_cash', '100.0000');
        $this->api('reconciliation')->assertOk()->assertJsonPath('data.reconciliation.closed_by', $this->actor);
        $this->api('history', ['date' => '2026-11-17', 'closed_by' => $this->actor, 'status' => 'closed', 'per_page' => 10])->assertOk()->assertJsonPath('meta.total', 1);
        $this->api('history', ['page' => 0])->assertUnprocessable();
        $this->api('summary', id: strtolower((string) Str::ulid()))->assertNotFound();
    }

    public function test_current_permissions_token_revocation_and_inactive_accounts_protect_closing(): void
    {
        $body = ['counted_cash' => '100', 'observation' => 'Conteo'];
        $this->api('close', $body, (string) Str::uuid(), 'invalid')->assertUnauthorized();
        $users = app(UserAdministration::class);
        foreach (['waiter', 'cashier'] as $role) {
            $user = $users->create($this->actor, ['name' => 'Closing '.$role, 'username' => 'closing.'.$role, 'password' => 'EmployeeInitial!123']);
            $users->assignRole($this->actor, $user->id, $role);
            app(IdentityAccess::class)->changePassword($user->id, 'EmployeeInitial!123', 'EmployeeChanged!123');
            Auth::forgetGuards();
            $token = $this->postJson('/api/v1/auth/login', ['username' => 'closing.'.$role, 'password' => 'EmployeeChanged!123'])->assertOk()->json('data.token');
            if ($role === 'waiter') {
                $this->api('close', $body, (string) Str::uuid(), $token)->assertForbidden();
                $this->api('history', token: $token)->assertForbidden();
            } else {
                $key = (string) Str::uuid();
                $this->api('close', $body, $key, $token)->assertOk()->assertJsonPath('data.reconciliation.closed_by', $user->id);
                $users->setActive($this->actor, $user->id, false);
                $this->api('close', $body, $key, $token)->assertUnauthorized();
            }
        }
        $this->assertSame(1, $this->database->table('cash_reconciliations')->count());
        Auth::forgetGuards();
        $this->postJson('/api/v1/auth/logout', [], ['Authorization' => 'Bearer '.$this->token])->assertOk();
        $this->api('history')->assertUnauthorized();
    }

    public function test_internal_errors_are_sanitized_in_both_debug_modes_and_rollback(): void
    {
        Event::listen(SecurityEvent::class, function (SecurityEvent $event): void {
            if ($event->type === 'cash.session.closed') {
                throw new \RuntimeException('Secret SQL password C:\\private\\server');
            }
        });
        foreach ([true, false] as $debug) {
            config(['app.debug' => $debug]);
            $response = $this->api('close', ['counted_cash' => '100', 'observation' => 'Conteo'], (string) Str::uuid())->assertStatus(500);
            foreach (['Secret', 'password', 'private', 'trace', 'exception'] as $secret) {
                $this->assertStringNotContainsString($secret, $response->getContent());
            }
            $this->assertSame(0, $this->database->table('cash_reconciliations')->count());
            $this->assertSame('open', $this->database->table('cash_sessions')->value('status'));
        }
    }
}
