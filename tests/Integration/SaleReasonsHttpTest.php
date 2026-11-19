<?php

namespace Tests\Integration;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Sales\Application\SalesRevision;
use Modules\Sales\Contracts\SaleOperationReasons;
use Modules\Sales\Contracts\SalesOperations;
use Modules\Users\Contracts\IdentityAccess;
use Modules\Users\Contracts\UserAdministration;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ReasonsSqlServerTestCase;

class SaleReasonsHttpTest extends ReasonsSqlServerTestCase
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
        $response = $this->json($method, '/api/v1/sales'.$path, $data, ['Authorization' => 'Bearer '.$token, 'X-Request-Id' => 'reasons-contract',
            ...($revision === null ? [] : ['If-Match' => $revision]), ...($key === null ? [] : ['Idempotency-Key' => $key])]);
        $response->assertHeader('X-Request-Id', 'reasons-contract')->assertJsonPath('meta.request_id', 'reasons-contract');
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::$samples[] = ['method' => $method, 'path' => preg_replace('~/[0-7][0-9a-hjkmnp-tv-z]{25}~i', '/{id}', '/sales'.$path), 'status' => $response->status(), 'body' => $response->json()];

        return $response;
    }

    public static function tearDownAfterClass(): void
    {
        file_put_contents(dirname(__DIR__, 2).'/.tools/reasons-responses.json', json_encode(self::$samples, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        self::$samples = [];
        parent::tearDownAfterClass();
    }

    public function test_catalog_routes_resources_filters_order_pagination_and_optimistic_admin_changes(): void
    {
        $input = ['code' => 'QA_HTTP', 'name' => 'Motivo HTTP exclusivo de pruebas', 'description' => 'QA', 'operation' => 'sale_item_removal', 'display_order' => 1];
        $key = (string) Str::uuid();
        $created = $this->api('POST', '/reasons', $input, key: $key)->assertCreated()->assertJsonPath('success', true)->json('data.reason');
        $this->assertSame($created, $this->api('POST', '/reasons', $input, key: $key)->assertCreated()->json('data.reason'));
        $this->api('GET', '/reasons/'.$created['id'])->assertOk()->assertJsonPath('data.reason.code', 'QA_HTTP');
        $this->api('GET', '/reasons?operation=sale_item_removal&per_page=10')->assertOk()->assertJsonCount(2, 'data.reasons');
        $updated = $this->api('PUT', '/reasons/'.$created['id'], ['name' => 'Nombre actualizado', 'description' => null, 'display_order' => 0], '"'.$created['revision'].'"', (string) Str::uuid())->assertOk()->json('data.reason');
        $this->api('PUT', '/reasons/'.$created['id'], ['name' => 'Obsoleto', 'description' => null, 'display_order' => 0], '"'.$created['revision'].'"', (string) Str::uuid())->assertStatus(409);
        $disabled = $this->api('PATCH', '/reasons/'.$created['id'].'/status', ['is_active' => false], '"'.$updated['revision'].'"', (string) Str::uuid())->assertOk()->json('data.reason');
        $this->api('GET', '/reasons?operation=sale_item_removal')->assertOk()->assertJsonCount(1, 'data.reasons');
        $this->api('GET', '/reasons?administration=true&is_active=false')->assertOk()->assertJsonCount(1, 'data.reasons');
        $this->api('PATCH', '/reasons/'.$created['id'].'/status', ['is_active' => true], '"'.$disabled['revision'].'"', (string) Str::uuid())->assertOk();
        $this->api('DELETE', '/reasons/'.$created['id'], key: (string) Str::uuid())->assertStatus(405);
        $this->assertSame(1, $this->database->table('audit_security_events')->where('event_type', 'sales.reason.created')->where('record_id', $created['id'])->count());
    }

    public function test_sensitive_mutations_require_uuid_revision_and_strict_reason_payload(): void
    {
        $sale = $this->sale();
        $path = '/'.$sale->sale->id.'/items/'.$sale->items[0]->id;
        $this->api('DELETE', $path, ['reason_code' => 'QA_REMOVE'], $this->revision($sale))->assertBadRequest();
        $this->api('DELETE', $path, ['reason_code' => 'QA_REMOVE'], $this->revision($sale), 'not-a-uuid')->assertBadRequest();
        $this->api('DELETE', $path, [], $this->revision($sale), (string) Str::uuid())->assertUnprocessable();
        $this->api('DELETE', $path, ['reason_code' => 'QA_REMOVE', 'removed_by' => $this->actor], $this->revision($sale), (string) Str::uuid())->assertUnprocessable();
        $this->api('DELETE', $path, ['reason_code' => 'QA_REMOVE'], null, (string) Str::uuid())->assertStatus(409);
        $key = (string) Str::uuid();
        $first = $this->api('DELETE', $path, ['reason_code' => 'QA_REMOVE'], $this->revision($sale), $key)->assertOk()->assertJsonPath('data.total', '0.0000')->json('data');
        $this->assertSame($first, $this->api('DELETE', $path, ['reason_code' => 'QA_REMOVE'], $this->revision($sale), $key)->assertOk()->json('data'));
        $this->api('DELETE', $path, ['reason_code' => 'QA_CANCEL'], $this->revision($sale), $key)->assertStatus(409);
        $this->assertArrayHasKey('removal_reason', $first['items'][0]);
        $this->assertArrayNotHasKey('revision', $first['items'][0]['removal_reason']);
        $current = app(SalesOperations::class)->sale($this->actor, $sale->sale->id);
        $this->api('POST', '/'.$sale->sale->id.'/cancel', ['reason_code' => 'QA_CANCEL'], $this->revision($current), (string) Str::uuid())->assertOk()->assertJsonPath('data.state', 'cancelled');
    }

    public static function roles(): array
    {
        return [['admin', true, true], ['supervisor', true, false], ['cashier', false, false], ['waiter', false, false]];
    }

    #[DataProvider('roles')]
    public function test_existing_roles_enforce_reason_administration_and_both_sensitive_operations(string $role, bool $operate, bool $admin): void
    {
        $users = app(UserAdministration::class);
        $user = $users->create($this->actor, ['name' => 'QA '.$role, 'username' => 'reason.'.$role, 'password' => 'EmployeeInitial!123']);
        $users->assignRole($this->actor, $user->id, $role);
        app(IdentityAccess::class)->changePassword($user->id, 'EmployeeInitial!123', 'EmployeeChanged!123');
        $token = $this->token('reason.'.$role, 'EmployeeChanged!123');
        $this->api('GET', '/reasons?administration=true', token: $token)->assertStatus($admin ? 200 : 403);
        $this->api('GET', '/reasons?operation=sale_item_removal', token: $token)->assertStatus($operate ? 200 : 403);
        $sale = $this->sale();
        $this->api('DELETE', '/'.$sale->sale->id.'/items/'.$sale->items[0]->id, ['reason_code' => 'QA_REMOVE'], $this->revision($sale), (string) Str::uuid(), $token)->assertStatus($operate ? 200 : 403);
        $current = app(SalesOperations::class)->sale($this->actor, $sale->sale->id);
        $key = (string) Str::uuid();
        $this->api('POST', '/'.$sale->sale->id.'/cancel', ['reason_code' => 'QA_CANCEL'], $this->revision($current), $key, $token)->assertStatus($operate ? 200 : 403);
        if ($operate) {
            $users->removeRole($this->actor, $user->id, $role);
            $this->api('POST', '/'.$sale->sale->id.'/cancel', ['reason_code' => 'QA_CANCEL'], $this->revision($current), $key, $token)->assertStatus(403);
        }
    }

    public function test_empty_catalog_foreign_item_and_cancelled_sale_fail_closed_and_legacy_revision_is_unchanged(): void
    {
        foreach ([$this->removal, $this->cancellation] as $reason) {
            app(SaleOperationReasons::class)->write($this->actor, 'status', ['is_active' => false], (string) Str::uuid(), $reason['id'], '"'.$reason['revision'].'"');
        }
        $this->api('GET', '/reasons?operation=sale_cancellation')->assertOk()->assertJsonCount(0, 'data.reasons');
        $sale = $this->sale();
        $legacy = json_decode(json_encode($sale, JSON_THROW_ON_ERROR), true);
        unset($legacy['sale']['reasonSnapshot']);
        unset($legacy['sale']['discountAuthorization']);
        foreach ($legacy['items'] as &$item) {
            unset($item['reasonSnapshot']);
        }
        unset($item);
        $this->assertSame(hash_hmac('sha256', json_encode($legacy, JSON_THROW_ON_ERROR), config('app.key')), SalesRevision::of($sale));
        $other = $this->sale();
        $this->api('DELETE', '/'.$sale->sale->id.'/items/'.$other->items[0]->id, ['reason_code' => 'QA_REMOVE'], $this->revision($sale), (string) Str::uuid())->assertNotFound();
        $this->api('POST', '/'.$sale->sale->id.'/cancel', ['reason_code' => 'QA_CANCEL'], $this->revision($sale), (string) Str::uuid())->assertStatus(409);
        $this->assertEquals($sale, app(SalesOperations::class)->sale($this->actor, $sale->sale->id));
    }

    public function test_catalog_validation_and_sql_errors_are_uniform_and_sanitized_in_both_debug_modes(): void
    {
        foreach ([false, true] as $debug) {
            config(['app.debug' => $debug]);
            $this->api('POST', '/reasons', ['code' => 'QA_REMOVE', 'name' => 'Duplicate', 'operation' => 'sale_item_removal'], key: (string) Str::uuid())->assertStatus(409)->assertDontSee('SQLSTATE')->assertDontSee('C:\\Repos');
            $this->api('POST', '/reasons', ['code' => 'BAD CODE', 'name' => 'Invalid', 'operation' => 'refund'], key: (string) Str::uuid())->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR');
            $this->api('PUT', '/reasons/'.$this->removal['id'], ['code' => 'CHANGED', 'name' => 'Changed'], '"'.$this->removal['revision'].'"', (string) Str::uuid())->assertUnprocessable();
        }
    }
}
