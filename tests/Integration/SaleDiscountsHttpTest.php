<?php

namespace Tests\Integration;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Sales\Contracts\SalesOperations;
use Modules\Users\Contracts\IdentityAccess;
use Modules\Users\Contracts\UserAdministration;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\DiscountsSqlServerTestCase;

class SaleDiscountsHttpTest extends DiscountsSqlServerTestCase
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
        $response = $this->json($method, '/api/v1/sales'.$path, $data, ['Authorization' => 'Bearer '.$token, 'X-Request-Id' => 'discounts-contract',
            ...($revision === null ? [] : ['If-Match' => $revision]), ...($key === null ? [] : ['Idempotency-Key' => $key])]);
        $response->assertHeader('X-Request-Id', 'discounts-contract')->assertJsonPath('meta.request_id', 'discounts-contract');
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::$samples[] = ['method' => strtolower($method), 'path' => preg_replace('~/[0-7][0-9a-hjkmnp-tv-z]{25}(?=/|$)~i', '/{id}', '/sales'.$path), 'status' => $response->status(), 'body' => $response->json()];

        return $response;
    }

    public static function tearDownAfterClass(): void
    {
        file_put_contents(dirname(__DIR__, 2).'/.tools/discounts-responses.json', json_encode(self::$samples, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        self::$samples = [];
        parent::tearDownAfterClass();
    }

    public function test_put_and_delete_apply_replace_remove_with_resources_revision_and_durable_receipts(): void
    {
        $sale = $this->sale();
        $path = '/'.$sale->sale->id.'/discount';
        $key = (string) Str::uuid();
        $first = $this->api('PUT', $path, ['type' => 'percent', 'value' => 20], $this->revision($sale), $key)->assertOk()->assertJsonPath('data.discount', '36.0000')->assertJsonPath('data.total', '144.0000')->assertJsonPath('data.discount_authorization.authorized_by', $this->actor)->json('data');
        $this->assertSame($first, $this->api('PUT', $path, ['type' => 'percent', 'value' => 20], $this->revision($sale), $key)->assertOk()->json('data'));
        $this->api('PUT', $path, ['type' => 'percent', 'value' => 30], $this->revision($sale), $key)->assertConflict()->assertJsonPath('code', 'IDEMPOTENCY_KEY_CONFLICT');
        $this->api('PUT', $path, ['type' => 'fixed', 'value' => '50'], '"'.$first['revision'].'"', (string) Str::uuid())->assertOk()->assertJsonPath('data.discount', '50.0000')->assertJsonPath('data.total', '130.0000');
        $current = app(SalesOperations::class)->sale($this->actor, $sale->sale->id);
        $removeKey = (string) Str::uuid();
        $removed = $this->api('DELETE', $path, [], $this->revision($current), $removeKey)->assertOk()->assertJsonPath('data.discount', '0.0000')->assertJsonPath('data.total', '180.0000')->assertJsonMissingPath('data.discount_authorization')->json('data');
        $this->assertSame($removed, $this->api('DELETE', $path, [], $this->revision($current), $removeKey)->assertOk()->json('data'));
    }

    public function test_strict_inputs_keys_and_revisions_prevent_financial_mass_assignment(): void
    {
        $sale = $this->sale();
        $path = '/'.$sale->sale->id.'/discount';
        $this->api('PUT', $path, ['type' => 'percent', 'value' => 20], $this->revision($sale))->assertBadRequest();
        $this->api('PUT', $path, ['type' => 'percent', 'value' => 20], $this->revision($sale), 'invalid')->assertBadRequest();
        $this->api('PUT', $path, ['type' => 'percent', 'value' => 20], null, (string) Str::uuid())->assertConflict();
        foreach ([['type' => 'percent', 'value' => 0], ['type' => 'percent', 'value' => 101], ['type' => 'percent', 'value' => 1.5], ['type' => 'percent', 'value' => '20'], ['type' => 'fixed', 'value' => 20], ['type' => 'fixed', 'value' => '0'], ['type' => 'fixed', 'value' => '-1'], ['type' => 'fixed', 'value' => '1.00001'], ['type' => 'percent', 'value' => 20, 'subtotal' => '1'], ['type' => 'percent', 'value' => 20, 'authorized_by' => $this->actor]] as $body) {
            $this->api('PUT', $path, $body, $this->revision($sale), (string) Str::uuid())->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR');
        }
        $this->api('PUT', $path, ['type' => 'fixed', 'value' => '181'], $this->revision($sale), (string) Str::uuid())->assertConflict();
        $this->api('DELETE', $path, ['discount_amount' => '0'], $this->revision($sale), (string) Str::uuid())->assertUnprocessable();
        $this->assertEquals($sale, app(SalesOperations::class)->sale($this->actor, $sale->sale->id));
    }

    public static function roles(): array
    {
        return [['admin', true], ['supervisor', true], ['cashier', false], ['waiter', false]];
    }

    #[DataProvider('roles')]
    public function test_real_role_permissions_allow_only_authorized_actors_and_are_rechecked_before_replay(string $role, bool $allowed): void
    {
        $users = app(UserAdministration::class);
        $user = $users->create($this->actor, ['name' => 'QA '.$role, 'username' => 'discount.'.$role, 'password' => 'EmployeeInitial!123']);
        $users->assignRole($this->actor, $user->id, $role);
        app(IdentityAccess::class)->changePassword($user->id, 'EmployeeInitial!123', 'EmployeeChanged!123');
        $token = $this->token('discount.'.$role, 'EmployeeChanged!123');
        $sale = $this->sale();
        $path = '/'.$sale->sale->id.'/discount';
        $key = (string) Str::uuid();
        $response = $this->api('PUT', $path, ['type' => 'percent', 'value' => 20], $this->revision($sale), $key, $token)->assertStatus($allowed ? 200 : 403);
        if ($allowed) {
            $response->assertJsonPath('data.discount_authorization.authorized_by', $user->id);
            $users->removeRole($this->actor, $user->id, $role);
            $this->api('PUT', $path, ['type' => 'percent', 'value' => 20], $this->revision($sale), $key, $token)->assertForbidden();
        } else {
            $this->api('DELETE', $path, [], $this->revision($sale), (string) Str::uuid(), $token)->assertForbidden();
        }
    }

    public function test_zero_checkout_resource_has_folio_empty_payments_and_authorized_discount_not_cancellation(): void
    {
        $sale = $this->sale();
        $path = '/'.$sale->sale->id;
        $discount = $this->api('PUT', $path.'/discount', ['type' => 'fixed', 'value' => '180'], $this->revision($sale), (string) Str::uuid())->assertOk()->json('data');
        $key = (string) Str::uuid();
        $closed = $this->api('POST', $path.'/checkout', ['payments' => []], '"'.$discount['revision'].'"', $key)->assertOk()->assertJsonPath('data.state', 'closed')->assertJsonPath('data.total', '0.0000')->assertJsonCount(0, 'data.payments')->assertJsonMissingPath('data.cancellation_reason')->json('data');
        $this->assertNotEmpty($closed['folio']);
        $this->api('PUT', $path.'/discount', ['type' => 'percent', 'value' => 20], '"'.$closed['revision'].'"', (string) Str::uuid())->assertConflict();
        $this->api('DELETE', $path.'/discount', [], '"'.$closed['revision'].'"', (string) Str::uuid())->assertConflict();
    }

    public function test_revoked_token_or_session_and_inactive_user_cannot_apply_discount(): void
    {
        $users = app(UserAdministration::class);
        $user = $users->create($this->actor, ['name' => 'QA supervisor', 'username' => 'discount.revoked', 'password' => 'EmployeeInitial!123']);
        $users->assignRole($this->actor, $user->id, 'supervisor');
        app(IdentityAccess::class)->changePassword($user->id, 'EmployeeInitial!123', 'EmployeeChanged!123');
        $token = $this->token('discount.revoked', 'EmployeeChanged!123');
        $sale = $this->sale();
        $users->setActive($this->actor, $user->id, false);
        $this->api('PUT', '/'.$sale->sale->id.'/discount', ['type' => 'percent', 'value' => 20], $this->revision($sale), (string) Str::uuid(), $token)->assertUnauthorized();
        $this->assertNull(app(SalesOperations::class)->sale($this->actor, $sale->sale->id)->sale->discountAuthorization);
    }

    public static function revokedAccess(): array
    {
        return [['token'], ['session']];
    }

    #[DataProvider('revokedAccess')]
    public function test_independently_revoked_token_or_session_prevents_discount(string $kind): void
    {
        $token = $this->token();
        $sale = $this->sale();
        if ($kind === 'token') {
            $this->database->table('personal_access_tokens')->delete();
        } else {
            $this->database->table('auth_access_sessions')->where('channel', 'api')->update(['revoked_at' => now()]);
        }
        $this->api('PUT', '/'.$sale->sale->id.'/discount', ['type' => 'percent', 'value' => 20], $this->revision($sale), (string) Str::uuid(), $token)->assertUnauthorized();
        $this->assertNull(app(SalesOperations::class)->sale($this->actor, $sale->sale->id)->sale->discountAuthorization);
    }
}
