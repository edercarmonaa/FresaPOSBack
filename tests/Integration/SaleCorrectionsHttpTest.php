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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CorrectionsSqlServerTestCase;

class SaleCorrectionsHttpTest extends CorrectionsSqlServerTestCase
{
    private static array $samples = [];

    private ?string $adminToken = null;

    private function token(string $username = 'finance.fixture', string $password = 'FinanceChanged!123'): string
    {
        Auth::forgetGuards();

        return $this->postJson('/api/v1/auth/login', compact('username', 'password'))->assertOk()->json('data.token');
    }

    private function api(string $method, string $path, array $data = [], ?string $revision = null, ?string $key = null, ?string $token = null): TestResponse
    {
        $token ??= $this->adminToken ??= $this->token();
        Auth::forgetGuards();
        $response = $this->json($method, '/api/v1/sales'.$path, $data, ['Authorization' => 'Bearer '.$token, 'X-Request-Id' => 'corrections-contract',
            ...($revision === null ? [] : ['If-Match' => $revision]), ...($key === null ? [] : ['Idempotency-Key' => $key])]);
        $response->assertHeader('X-Request-Id', 'corrections-contract')->assertJsonPath('meta.request_id', 'corrections-contract');
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::$samples[] = ['method' => strtolower($method), 'path' => preg_replace('~/[0-7][0-9a-hjkmnp-tv-z]{25}(?=/|$)~i', '/{id}', '/sales'.$path), 'status' => $response->status(), 'body' => $response->json()];

        return $response;
    }

    public static function tearDownAfterClass(): void
    {
        file_put_contents(dirname(__DIR__, 2).'/.tools/corrections-responses.json', json_encode(self::$samples, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        self::$samples = [];
        parent::tearDownAfterClass();
    }

    public function test_complete_rest_flow_and_replay_preserve_payment_and_folio_and_publish_paginated_history(): void
    {
        $paid = $this->settledSale();
        $path = '/'.$paid->sale->id;
        $key = (string) Str::uuid();
        $row = $this->api('POST', $path.'/reopen', [], $this->revision($paid), $key)->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.observation', null)->json('data');
        $this->assertSame($row, $this->api('POST', $path.'/reopen', [], $this->revision($paid), $key)->assertOk()->json('data'));
        $this->api('GET', $path.'/correction')->assertOk()->assertJsonPath('data.id', $row['id']);
        $edit = $this->api('PATCH', $path.'/correction', ['action' => 'note', 'note' => 'Corrección API'], '"'.$row['revision'].'"', (string) Str::uuid())->assertOk()->json('data');
        $confirmed = $this->api('POST', $path.'/correction/confirm', ['payments' => []], '"'.$edit['revision'].'"', (string) Str::uuid())->assertOk()->assertJsonPath('data.status', 'confirmed')->assertJsonPath('data.sale.note', 'Corrección API')->json('data');
        $this->assertSame($paid->sale->folio, $confirmed['folio']);
        $this->assertSame($paid->payments, $confirmed['sale']['payments']);
        $this->api('GET', $path.'/corrections')->assertOk()->assertJsonCount(1, 'data.corrections')->assertJsonPath('meta.total', 1)->assertJsonPath('meta.per_page', 10);
        $this->api('GET', $path.'/correction')->assertNotFound();
        $second = $this->api('POST', $path.'/reopen', [], $this->revision(app(SalesOperations::class)->sale($this->actor, $paid->sale->id)), (string) Str::uuid())->assertOk()->json('data');
        $this->api('POST', $path.'/correction/discard', [], '"'.$second['revision'].'"', (string) Str::uuid())->assertOk()->assertJsonPath('data.status', 'discarded');
        $this->api('GET', $path.'/corrections')->assertOk()->assertJsonCount(2, 'data.corrections');
    }

    public function test_keys_preconditions_and_forbidden_financial_fields_are_enforced(): void
    {
        $paid = $this->settledSale();
        $path = '/'.$paid->sale->id;
        $this->api('POST', $path.'/reopen', [], $this->revision($paid))->assertBadRequest();
        $this->api('POST', $path.'/reopen', [], $this->revision($paid), 'bad')->assertBadRequest();
        $this->api('POST', $path.'/reopen', [], null, (string) Str::uuid())->assertConflict();
        foreach ([['folio' => '202601010001'], ['actor_id' => $this->actor], ['cash_session_id' => $this->sessionId], ['total' => '1'], ['closed_at' => '2026-01-01'], ['observation' => str_repeat('x', 151)]] as $input) {
            $this->api('POST', $path.'/reopen', $input, $this->revision($paid), (string) Str::uuid())->assertUnprocessable();
        }
        $row = $this->api('POST', $path.'/reopen', ['observation' => str_repeat('x', 150)], $this->revision($paid), (string) Str::uuid())->assertOk()->json('data');
        foreach ([['action' => 'quantity', 'item_id' => $paid->items[0]->id, 'quantity' => '2'], ['action' => 'add', 'product_id' => $this->productId, 'quantity' => 1, 'price' => '0'], ['action' => 'customer', 'customer_id' => (string) Str::ulid()], ['action' => 'discount', 'type' => 'percent', 'value' => '20'], ['action' => 'note', 'note' => '<script>bad</script>']] as $input) {
            $this->api('PATCH', $path.'/correction', $input, '"'.$row['revision'].'"', (string) Str::uuid())->assertUnprocessable();
        }
        $this->api('POST', $path.'/correction/confirm', ['payments' => [], 'folio' => '202601010001'], '"'.$row['revision'].'"', (string) Str::uuid())->assertUnprocessable();
        $this->assertEquals($paid, app(SalesOperations::class)->sale($this->actor, $paid->sale->id));
    }

    public static function roles(): array
    {
        return [['admin', true], ['supervisor', false], ['cashier', false], ['waiter', false]];
    }

    #[DataProvider('roles')]
    public function test_existing_permissions_are_required_for_reopening_and_rechecked_before_replay(string $role, bool $allowed): void
    {
        $users = app(UserAdministration::class);
        $user = $users->create($this->actor, ['name' => 'Correction '.$role, 'username' => 'correction.'.$role, 'password' => 'EmployeeInitial!123']);
        $users->assignRole($this->actor, $user->id, $role);
        app(IdentityAccess::class)->changePassword($user->id, 'EmployeeInitial!123', 'EmployeeChanged!123');
        $token = $this->token('correction.'.$role, 'EmployeeChanged!123');
        $paid = $this->settledSale();
        $key = (string) Str::uuid();
        $path = '/'.$paid->sale->id;
        $response = $this->api('POST', $path.'/reopen', [], $this->revision($paid), $key, $token)->assertStatus($allowed ? 200 : 403);
        if ($allowed) {
            $response->assertJsonPath('data.reopened_by', $user->id);
            $users->removeRole($this->actor, $user->id, $role);
            $this->api('POST', $path.'/reopen', [], $this->revision($paid), $key, $token)->assertForbidden();
        } else {
            $this->assertSame(0, $this->database->table('sales_corrections')->count());
        }
    }

    public static function revocations(): array
    {
        return [['token'], ['session'], ['inactive']];
    }

    #[DataProvider('revocations')]
    public function test_revoked_access_cannot_reopen_or_confirm_or_retrieve_history(string $kind): void
    {
        $users = app(UserAdministration::class);
        $user = $users->create($this->actor, ['name' => 'Revoked correction', 'username' => 'correction.revoked', 'password' => 'EmployeeInitial!123']);
        $users->assignRole($this->actor, $user->id, 'admin');
        app(IdentityAccess::class)->changePassword($user->id, 'EmployeeInitial!123', 'EmployeeChanged!123');
        $token = $this->token('correction.revoked', 'EmployeeChanged!123');
        $paid = $this->settledSale();
        $row = $this->reopen($paid);
        $path = '/'.$paid->sale->id;
        if ($kind === 'token') {
            $this->database->table('personal_access_tokens')->delete();
        } elseif ($kind === 'session') {
            $this->database->table('auth_access_sessions')->where('channel', 'api')->update(['revoked_at' => now()]);
        } else {
            $users->setActive($this->actor, $user->id, false);
        }
        $this->api('POST', $path.'/reopen', [], $this->revision($paid), (string) Str::uuid(), $token)->assertUnauthorized();
        $this->api('POST', $path.'/correction/confirm', ['payments' => []], '"'.$row['revision'].'"', (string) Str::uuid(), $token)->assertUnauthorized();
        $this->api('GET', $path.'/corrections', token: $token)->assertUnauthorized();
        $this->assertSame('active', $this->database->table('sales_corrections')->value('status'));
    }

    public function test_financial_refund_rejection_and_additional_collection_use_explicit_payments(): void
    {
        $paid = $this->settledSale();
        $row = $this->reopen($paid);
        $path = '/'.$paid->sale->id;
        $row = $this->api('PATCH', $path.'/correction', ['action' => 'discount', 'type' => 'percent', 'value' => 20], '"'.$row['revision'].'"', (string) Str::uuid())->assertOk()->json('data');
        $this->api('POST', $path.'/correction/confirm', ['payments' => []], '"'.$row['revision'].'"', (string) Str::uuid())->assertConflict();
        $row = $this->api('PATCH', $path.'/correction', ['action' => 'discount-remove'], '"'.$row['revision'].'"', (string) Str::uuid())->assertOk()->json('data');
        $row = $this->api('PATCH', $path.'/correction', ['action' => 'quantity', 'item_id' => $paid->items[0]->id, 'quantity' => 2], '"'.$row['revision'].'"', (string) Str::uuid())->assertOk()->json('data');
        $this->api('POST', $path.'/correction/confirm', ['payments' => []], '"'.$row['revision'].'"', (string) Str::uuid())->assertUnprocessable();
        $this->api('POST', $path.'/correction/confirm', ['payments' => [$this->payment('cash', '180', '200')]], '"'.$row['revision'].'"', (string) Str::uuid())->assertOk()->assertJsonPath('data.financial.additional_paid', '180.0000')->assertJsonCount(2, 'data.sale.payments');
    }

    public static function debugModes(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('debugModes')]
    public function test_internal_confirmation_errors_are_sanitized_with_debug_enabled_or_disabled(bool $debug): void
    {
        config(['app.debug' => $debug]);
        $paid = $this->settledSale();
        $row = $this->reopen($paid);
        $row = $this->correction($row, 'edit', ['action' => 'note', 'note' => 'API protegida']);
        Event::listen(SecurityEvent::class, function ($event): void {
            if ($event->type === 'sales.correction.confirmed') {
                throw new \RuntimeException('CORRECTION_SECRET SELECT password FROM private C:\\private\\server.php');
            }
        });
        $response = $this->api('POST', '/'.$paid->sale->id.'/correction/confirm', ['payments' => []], '"'.$row['revision'].'"', (string) Str::uuid())->assertStatus(500);
        foreach (['CORRECTION_SECRET', 'SELECT password', 'server.php', 'trace', 'exception'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $this->assertEquals($paid, app(SalesOperations::class)->sale($this->actor, $paid->sale->id));
        $this->assertSame('active', $this->database->table('sales_corrections')->value('status'));
    }
}
