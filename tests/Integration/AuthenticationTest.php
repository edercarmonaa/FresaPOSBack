<?php

namespace Tests\Integration;

use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Auth\Application\SessionRegistry;
use Modules\Auth\Infrastructure\Models\AccessSession;
use Modules\Auth\Infrastructure\Models\AccessToken;
use Modules\Auth\Infrastructure\Models\EmployeePin;
use Modules\Core\Contracts\ApiResponder;
use Modules\Users\Application\BootstrapAdministrator;
use Modules\Users\Contracts\IdentityAccess;
use Modules\Users\Contracts\UserAdministration;
use Modules\Users\Infrastructure\Persistence\Models\User;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\AuthSqlServerTestCase;

class AuthenticationTest extends AuthSqlServerTestCase
{
    private string $admin;

    private string $cashier;

    private string $waiter;

    private array $cookieJar = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = app(BootstrapAdministrator::class)->create('Admin test', 'admin.test', 'AdminInitial!123');
        app(IdentityAccess::class)->changePassword($this->admin, 'AdminInitial!123', 'AdminChanged!123');
        $users = app(UserAdministration::class);
        $this->cashier = $users->create($this->admin, ['name' => 'Cashier test', 'username' => 'cashier.test', 'password' => 'CashierInitial!123'])->id;
        $users->assignRole($this->admin, $this->cashier, 'cashier');
        app(IdentityAccess::class)->changePassword($this->cashier, 'CashierInitial!123', 'CashierChanged!123');
        $this->waiter = $users->create($this->admin, ['name' => 'Waiter test', 'username' => 'waiter.test', 'password' => 'WaiterInitial!123'])->id;
        $users->assignRole($this->admin, $this->waiter, 'waiter');
        app(IdentityAccess::class)->changePassword($this->waiter, 'WaiterInitial!123', 'WaiterChanged!123');
        Route::get('api/v1/_test/auth-permission', fn (Request $request, ApiResponder $response) => $response->success($request, ['authorized' => true]))
            ->middleware(['api', 'auth:sanctum', 'auth.access.api', 'can:cash.open']);
    }

    private function api(string $method, string $uri, array $data = [], ?string $token = null): TestResponse
    {
        Auth::forgetGuards();

        return $this->json($method, $uri, $data, $token ? ['Authorization' => 'Bearer '.$token] : []);
    }

    private function web(string $method, string $uri, array $data = [], bool $livewire = false): TestResponse
    {
        Auth::forgetGuards();
        Auth::shouldUse('web');
        $response = $livewire
            ? $this->call($method, $uri, [], $this->cookieJar, [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_LIVEWIRE' => 'true'], json_encode($data, JSON_THROW_ON_ERROR))
            : $this->call($method, $uri, $data, $this->cookieJar, [], ['HTTP_ACCEPT' => 'text/html']);
        foreach ($response->headers->getCookies() as $cookie) {
            $this->cookieJar[$cookie->getName()] = $cookie->getValue();
        }

        return $response;
    }

    private function apiLogin(string $username = 'cashier.test', string $password = 'CashierChanged!123'): string
    {
        $response = $this->api('POST', '/api/v1/auth/login', ['username' => $username, 'password' => $password, 'device_name' => 'test-device']);
        $response->assertOk()->assertJsonPath('success', true)->assertJsonPath('code', 'OPERATION_SUCCESS');

        return $response->json('data.token');
    }

    private function webLogin(string $username = 'cashier.test', string $password = 'CashierChanged!123'): TestResponse
    {
        return $this->web('POST', '/login', ['username' => $username, 'password' => $password]);
    }

    public function test_web_login_rotates_id_and_csrf_cookie_logout_invalidates_old_access(): void
    {
        $this->web('GET', '/login')->assertOk()->assertSee('wire:submit="login"', false);
        $previousId = session()->getId();
        $previousCsrf = session()->token();
        $this->webLogin()->assertRedirect(route('auth.account'));
        $this->assertNotSame($previousId, session()->getId());
        $this->assertNotSame($previousCsrf, session()->token());
        $loggedId = session()->getId();
        $loggedCsrf = session()->token();
        $oldCookies = $this->cookieJar;
        $this->web('GET', '/account')->assertOk()->assertSee('Cashier test');
        $this->web('GET', '/admin')->assertForbidden();
        $this->web('POST', '/logout')->assertRedirect(route('login'));
        $this->assertNotSame($loggedId, session()->getId());
        $this->assertNotSame($loggedCsrf, session()->token());
        $this->assertNotNull(AccessSession::query()->where('session_hash', hash('sha256', $loggedId))->firstOrFail()->revoked_at);
        $this->cookieJar = $oldCookies;
        $this->web('GET', '/account')->assertRedirect(route('login'));
    }

    public function test_web_idle_expiry_is_server_side_and_activity_refreshes_shared_session(): void
    {
        $this->webLogin();
        $this->travel(29)->minutes();
        $this->web('GET', '/account')->assertOk();
        $this->travel(29)->minutes();
        $this->web('GET', '/account')->assertOk();
        $this->travel(31)->minutes();
        $this->web('GET', '/account')->assertRedirect(route('login'));
        $this->assertSame(30, config('session.lifetime'));
    }

    public function test_api_token_hash_expiry_user_contract_and_logout_revokes_only_current_token(): void
    {
        $token = $this->apiLogin();
        [$id, $secret] = explode('|', $token, 2);
        $stored = AccessToken::query()->findOrFail($id);
        $this->assertSame(hash('sha256', $secret), $stored->getAttribute('token'));
        $this->assertNotSame($secret, $stored->getAttribute('token'));
        $this->assertArrayNotHasKey('token', $stored->toArray());
        $this->assertEqualsWithDelta(now()->addMinutes(480)->getTimestamp(), $stored->expires_at->getTimestamp(), 2);
        $this->assertSame($this->cashier, $stored->tokenable_id);
        $second = $this->apiLogin();
        $response = $this->api('GET', '/api/v1/auth/me', [], $token)->assertOk()->assertJsonPath('data.user.id', $this->cashier);
        foreach (['password', 'pin_hash', 'password_hash', 'remember_token'] as $field) {
            $response->assertJsonMissingPath('data.user.'.$field);
        }
        $this->assertSame($response->headers->get('X-Request-Id'), $response->json('meta.request_id'));
        $this->api('POST', '/api/v1/auth/logout', [], $token)->assertOk();
        $this->api('GET', '/api/v1/auth/me', [], $token)->assertUnauthorized();
        $this->api('GET', '/api/v1/auth/me', [], $second)->assertOk();
        $this->assertSame(1, AccessToken::query()->count());
    }

    public function test_invalid_inactive_deleted_and_unknown_credentials_use_same_public_error(): void
    {
        $this->api('POST', '/api/v1/auth/login')->assertUnprocessable()->assertJsonPath('errors.username.0', 'El nombre de usuario es obligatorio.');
        $credentials = ['username' => 'cashier.test', 'password' => 'incorrect'];
        $first = $this->api('POST', '/api/v1/auth/login', $credentials)->assertUnauthorized()->assertJsonPath('code', 'INVALID_CREDENTIALS');
        $message = $first->json('message');
        $this->api('POST', '/api/v1/auth/login', ['username' => 'unknown.test', 'password' => 'incorrect'])->assertUnauthorized()->assertJsonPath('message', $message);
        app(UserAdministration::class)->setActive($this->admin, $this->cashier, false);
        $this->api('POST', '/api/v1/auth/login', ['username' => 'cashier.test', 'password' => 'CashierChanged!123'])->assertUnauthorized()->assertJsonPath('code', 'INVALID_CREDENTIALS')->assertJsonPath('message', $message);
        app(UserAdministration::class)->delete($this->admin, $this->cashier);
        $this->api('POST', '/api/v1/auth/login', ['username' => 'cashier.test', 'password' => 'CashierChanged!123'])->assertUnauthorized()->assertJsonPath('message', $message);
        $this->api('POST', '/api/v1/auth/login', ['username' => 'cashier@example.test', 'password' => 'CashierChanged!123'])->assertUnauthorized();
        $this->assertSame(0, AccessToken::query()->count());
        $this->webLogin('waiter.test', 'incorrect')->assertSessionHasErrors('username');
    }

    public function test_api_requires_real_bearer_and_enforces_permissions_from_server(): void
    {
        $this->api('GET', '/api/v1/auth/me')->assertUnauthorized();
        $this->api('GET', '/api/v1/auth/me', [], 'invalid')->assertUnauthorized();
        $cashier = $this->apiLogin();
        $this->api('GET', '/api/v1/_test/auth-permission', [], $cashier)->assertOk();
        $waiter = $this->apiLogin('waiter.test', 'WaiterChanged!123');
        $this->api('GET', '/api/v1/_test/auth-permission', [], $waiter)->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');
        app(UserAdministration::class)->setRolePermissions($this->admin, 'cashier', ['tickets.view']);
        $this->api('GET', '/api/v1/_test/auth-permission', [], $cashier)->assertForbidden();
    }

    public function test_token_expiration_is_fixed_despite_activity(): void
    {
        config(['auth_access.api_token_minutes' => 10]);
        $token = $this->apiLogin();
        $this->travel(9)->minutes();
        $this->api('GET', '/api/v1/auth/me', [], $token)->assertOk();
        $this->travel(2)->minutes();
        $this->api('GET', '/api/v1/auth/me', [], $token)->assertUnauthorized();
    }

    public function test_password_change_is_required_and_rotates_tokens_without_reusing_history(): void
    {
        $id = app(UserAdministration::class)->create($this->admin, ['name' => 'New user', 'username' => 'new.user', 'password' => 'InitialUser!123'])->id;
        app(UserAdministration::class)->assignRole($this->admin, $id, 'cashier');
        $token = $this->apiLogin('new.user', 'InitialUser!123');
        $this->api('GET', '/api/v1/_test/auth-permission', [], $token)->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
        $this->api('POST', '/api/v1/auth/password', ['current_password' => 'InitialUser!123', 'password' => 'InitialUser!123', 'password_confirmation' => 'InitialUser!123'], $token)->assertUnprocessable();
        $changed = $this->api('POST', '/api/v1/auth/password', ['current_password' => 'InitialUser!123', 'password' => 'ChangedUser!123', 'password_confirmation' => 'ChangedUser!123'], $token)->assertOk();
        $newToken = $changed->json('data.token');
        $this->assertNotSame($token, $newToken);
        $this->api('GET', '/api/v1/auth/me', [], $token)->assertUnauthorized();
        $this->api('GET', '/api/v1/_test/auth-permission', [], $newToken)->assertOk();
        $this->assertFalse(User::query()->findOrFail($id)->must_change_password);
    }

    public function test_web_forced_password_flow_and_admin_permission(): void
    {
        $id = app(UserAdministration::class)->create($this->admin, ['name' => 'New employee', 'username' => 'fresh.employee', 'password' => 'FreshInitial!123'])->id;
        $this->webLogin('fresh.employee', 'FreshInitial!123')->assertRedirect(route('auth.password'));
        $this->web('GET', '/account')->assertRedirect(route('auth.password'));
        $this->web('POST', '/auth/password', ['current_password' => 'FreshInitial!123', 'password' => 'FreshChanged!123', 'password_confirmation' => 'FreshChanged!123'])->assertRedirect(route('auth.account'));
        $this->assertFalse(User::query()->findOrFail($id)->must_change_password);
        $this->web('GET', '/account')->assertOk();
        $this->webLogin('admin.test', 'AdminChanged!123')->assertRedirect(route('auth.account'));
        $this->web('GET', '/admin')->assertOk();
    }

    public function test_pin_hash_change_disable_and_no_secret_flash(): void
    {
        $this->webLogin();
        $this->web('POST', '/auth/pin', ['current_password' => 'CashierChanged!123', 'pin' => '0042', 'pin_confirmation' => '0042'])->assertStatus(302);
        $stored = EmployeePin::query()->findOrFail($this->cashier);
        $this->assertTrue(Hash::check('0042', $stored->pin_hash));
        $this->assertArrayNotHasKey('pin_hash', $stored->toArray());
        $this->web('POST', '/auth/pin', ['current_password' => 'CashierChanged!123', 'pin' => '654321', 'pin_confirmation' => '654321'])->assertStatus(302);
        $this->assertFalse(Hash::check('0042', $stored->fresh()->pin_hash));
        $this->assertTrue(Hash::check('654321', $stored->fresh()->pin_hash));
        $this->web('POST', '/auth/pin', ['current_password' => 'CashierChanged!123', 'pin' => 'abc', 'pin_confirmation' => 'abc'])->assertSessionHasErrors('pin');
        $this->assertArrayNotHasKey('pin', session()->getOldInput());
        $this->web('POST', '/auth/pin/disable', ['current_password' => 'CashierChanged!123'])->assertStatus(302);
        $this->assertNull(EmployeePin::query()->find($this->cashier));
    }

    public function test_terminal_switch_requires_context_correct_pin_and_isolates_employee_permissions(): void
    {
        $this->webLogin();
        $this->web('POST', '/auth/pin', ['current_password' => 'CashierChanged!123', 'pin' => '0042', 'pin_confirmation' => '0042']);
        $this->webLogin('waiter.test', 'WaiterChanged!123');
        $this->web('POST', '/auth/pin', ['current_password' => 'WaiterChanged!123', 'pin' => '5678', 'pin_confirmation' => '5678']);
        $this->web('POST', '/auth/employee', ['username' => 'cashier.test', 'pin' => '0042'])->assertForbidden();
        $this->webLogin('admin.test', 'AdminChanged!123');
        $this->web('POST', '/auth/pin', ['current_password' => 'AdminChanged!123', 'pin' => '9999', 'pin_confirmation' => '9999']);
        $this->web('POST', '/auth/terminal', ['current_password' => 'AdminChanged!123'])->assertStatus(302);
        $oldId = session()->getId();
        $this->web('POST', '/auth/employee', ['username' => 'cashier.test', 'pin' => '1111'])->assertSessionHasErrors('pin');
        $this->web('POST', '/auth/employee', ['username' => 'cashier.test', 'pin' => '0042'])->assertRedirect(route('auth.account'));
        $this->assertNotSame($oldId, session()->getId());
        $this->web('GET', '/account')->assertOk()->assertSee('Cashier test')->assertDontSee('Empleado activo: <strong>Admin test', false);
        $this->web('GET', '/admin')->assertForbidden();
        $this->web('POST', '/auth/employee', ['username' => 'admin.test', 'pin' => '9999'])->assertForbidden();
        $this->web('POST', '/auth/employee', ['username' => 'waiter.test', 'pin' => '5678'])->assertRedirect(route('auth.account'));
        $this->web('GET', '/account')->assertSee('Waiter test');
        $record = AccessSession::query()->where('session_hash', hash('sha256', session()->getId()))->firstOrFail();
        $this->assertSame($this->waiter, $record->user_id);
        $this->assertSame($this->admin, $record->terminal_owner_id);
        $this->assertSame('pin', $record->getAttribute('auth_method'));
        $this->assertSame(0, AccessToken::query()->count());
    }

    public function test_session_listing_redacts_reusable_ids_and_blocks_horizontal_revocation(): void
    {
        $first = $this->apiLogin();
        $second = $this->apiLogin('waiter.test', 'WaiterChanged!123');
        $registry = app(SessionRegistry::class);
        $sessions = $registry->listOwn($this->cashier);
        $this->assertCount(1, $sessions);
        $this->assertArrayNotHasKey('token_id', $sessions[0]);
        $this->assertArrayNotHasKey('session_hash', $sessions[0]);
        try {
            $registry->revokeAuthorized($this->waiter, $sessions[0]['id']);
            $this->fail('No debe revocar accesos ajenos.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $registry->revokeAuthorized($this->cashier, $sessions[0]['id']);
        $this->api('GET', '/api/v1/auth/me', [], $first)->assertUnauthorized();
        $this->api('GET', '/api/v1/auth/me', [], $second)->assertOk();
    }

    public function test_account_state_change_revokes_web_and_api_access_after_commit(): void
    {
        $token = $this->apiLogin();
        $this->webLogin();
        app(UserAdministration::class)->setActive($this->admin, $this->cashier, false);
        $this->api('GET', '/api/v1/auth/me', [], $token)->assertUnauthorized();
        $this->web('GET', '/account')->assertRedirect(route('login'));
        $this->assertSame(0, AccessToken::query()->count());
        app(UserAdministration::class)->setActive($this->admin, $this->cashier, true);
        $this->api('GET', '/api/v1/auth/me', [], $token)->assertUnauthorized();
    }

    public function test_base_limits_and_login_throttle_are_enforced(): void
    {
        config(['auth_access.max_api_sessions' => 1]);
        $this->apiLogin();
        $this->api('POST', '/api/v1/auth/login', ['username' => 'cashier.test', 'password' => 'CashierChanged!123'])->assertStatus(429);
        for ($i = 0; $i < 5; $i++) {
            $this->api('POST', '/api/v1/auth/login', ['username' => 'unknown', 'password' => 'incorrect'])->assertUnauthorized();
        }
        $this->api('POST', '/api/v1/auth/login', ['username' => 'unknown', 'password' => 'incorrect'])->assertStatus(429);
    }

    public function test_login_csrf_is_enforced_and_secrets_are_not_flashed(): void
    {
        $this->app->instance('env', 'csrf-check');
        try {
            $this->web('POST', '/login', ['username' => 'cashier.test', 'password' => 'CashierChanged!123'])->assertStatus(419);
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    private function submitLivewireLogin(): TestResponse
    {
        // Usar el protocolo HTTP real: el helper Livewire::test omite StartSession.
        $initial = $this->web('GET', '/login')->assertOk();
        $document = new \DOMDocument;
        $document->loadHTML($initial->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        $root = (new \DOMXPath($document))->query('//*[@*[name()="wire:snapshot"]]')->item(0);
        $snapshot = $root->getAttribute('wire:snapshot');
        preg_match('/data-update-uri="([^"]+)"/', $initial->getContent(), $matches);
        $uri = html_entity_decode($matches[1]);

        return $this->web('POST', $uri, [
            '_token' => session()->token(),
            'components' => [['snapshot' => $snapshot, 'updates' => ['username' => 'cashier.test', 'password' => 'CashierChanged!123'], 'calls' => [['path' => '', 'method' => 'login', 'params' => []]]]],
        ], true);
    }

    public function test_livewire_login_clears_password_and_authenticates_using_real_service(): void
    {
        $response = $this->submitLivewireLogin()->assertOk();
        $updated = json_decode($response->json('components.0.snapshot'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('', $updated['data']['password']);
        $response->assertJsonPath('components.0.effects.redirect', route('auth.account'));
        $this->assertSame($this->cashier, Auth::guard('web')->id());
        $this->web('GET', '/account')->assertOk()->assertSee('Cashier test');
    }

    public function test_auth_tables_have_real_user_foreign_keys_and_native_ulids(): void
    {
        $this->apiLogin();
        foreach (['personal_access_tokens', 'auth_access_sessions'] as $table) {
            $column = $this->database->selectOne("SELECT TYPE_NAME(user_type_id) AS type_name, max_length FROM sys.columns WHERE object_id = OBJECT_ID(?) AND name = 'id'", [$table]);
            $this->assertSame('nchar', $column->type_name);
            $this->assertSame(52, (int) $column->max_length);
        }
        $this->expectException(QueryException::class);
        $this->database->table('auth_employee_pins')->insert(['user_id' => strtolower((string) Str::ulid()), 'pin_hash' => 'not-a-user']);
    }

    public function test_web_limits_preserve_existing_browser_and_api_ignores_web_cookies(): void
    {
        config(['auth_access.max_web_sessions' => 1]);
        $this->webLogin()->assertRedirect(route('auth.account'));
        $firstBrowser = $this->cookieJar;
        $this->cookieJar = [];
        $this->webLogin()->assertStatus(429);
        $this->cookieJar = $firstBrowser;
        $this->web('GET', '/account')->assertOk();
        Auth::forgetGuards();
        $this->call('GET', '/api/v1/auth/me', [], $firstBrowser, [], ['HTTP_ACCEPT' => 'application/json'])->assertUnauthorized();
    }

    public function test_pin_never_authenticates_api_and_requires_own_password_for_management(): void
    {
        $this->webLogin();
        $this->web('POST', '/auth/pin', ['current_password' => 'incorrect', 'pin' => '0042', 'pin_confirmation' => '0042'])->assertSessionHasErrors('current_password');
        $this->assertSame(0, EmployeePin::query()->count());
        $this->web('POST', '/auth/pin', ['current_password' => 'CashierChanged!123', 'pin' => '0042', 'pin_confirmation' => '0042'])->assertStatus(302);
        $this->api('POST', '/api/v1/auth/login', ['username' => 'cashier.test', 'password' => '0042'])->assertUnauthorized();
        $this->assertSame(0, AccessToken::query()->count());
    }

    public function test_pin_throttle_and_custom_administrative_capabilities_cannot_be_bypassed(): void
    {
        $this->webLogin();
        $this->web('POST', '/auth/pin', ['current_password' => 'CashierChanged!123', 'pin' => '0042', 'pin_confirmation' => '0042']);
        $this->web('POST', '/auth/terminal', ['current_password' => 'CashierChanged!123']);
        for ($i = 0; $i < 5; $i++) {
            $this->web('POST', '/auth/employee', ['username' => 'cashier.test', 'pin' => '1111'])->assertSessionHasErrors('pin');
        }
        $this->web('POST', '/auth/employee', ['username' => 'cashier.test', 'pin' => '0042'])->assertStatus(429);
        $this->travel(61)->seconds();
        $permissions = array_merge(config('users.security.initial_roles.cashier'), ['users.view']);
        app(UserAdministration::class)->setRolePermissions($this->admin, 'cashier', $permissions);
        $this->web('POST', '/auth/employee', ['username' => 'cashier.test', 'pin' => '0042'])->assertForbidden();
    }

    public function test_production_transport_rejects_http_before_issuing_tokens(): void
    {
        $this->app->instance('env', 'production');
        try {
            $this->api('POST', '/api/v1/auth/login', ['username' => 'cashier.test', 'password' => 'CashierChanged!123'])->assertBadRequest();
            $this->assertSame(0, AccessToken::query()->count());
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    public function test_auth_error_logs_and_responses_do_not_capture_tokens_passwords_or_pins(): void
    {
        $handler = new TestHandler;
        Log::extend('auth-memory', static fn () => new Logger('auth-test', [$handler]));
        config(['logging.default' => 'auth-memory', 'logging.channels.auth-memory' => ['driver' => 'auth-memory'], 'app.debug' => true]);
        $token = $this->apiLogin();
        Route::post('api/v1/_test/auth-error', function (): never {
            throw new \RuntimeException('Synthetic authentication error.');
        })->middleware(['api', 'auth:sanctum', 'auth.access.api']);
        $response = $this->api('POST', '/api/v1/_test/auth-error', ['password' => 'DoNotLog!123', 'pin' => '987654'], $token)->assertStatus(500)->assertJsonPath('code', 'INTERNAL_ERROR');
        $records = $handler->getRecords();
        $this->assertCount(1, $records);
        $formatted = (new LineFormatter)->format($records[0]);
        foreach ([$token, 'DoNotLog!123', '987654'] as $secret) {
            $this->assertStringNotContainsString($secret, $formatted);
            $response->assertDontSee($secret);
        }
        $this->assertSame($response->json('meta.request_id'), $records[0]->context['request_id']);
        foreach ($records[0]->context['exception']->getTrace() as $frame) {
            $this->assertArrayNotHasKey('args', $frame);
        }
    }

    public function test_html_auth_errors_never_dump_request_body_with_debug_enabled(): void
    {
        config(['app.debug' => true]);
        $this->database->getSchemaBuilder()->drop('auth_access_sessions');
        $response = $this->web('POST', '/login', ['username' => 'cashier.test', 'password' => 'CashierChanged!123'])->assertStatus(500);
        foreach (['CashierChanged!123', 'SQLSTATE', 'auth_access_sessions', 'stack', 'C:\\Repos'] as $secret) {
            $response->assertDontSee($secret);
        }
        $response->assertSee('No fue posible completar el acceso.');
    }

    public function test_livewire_auth_errors_never_dump_request_body_with_debug_enabled(): void
    {
        config(['app.debug' => true]);
        $this->database->getSchemaBuilder()->drop('auth_access_sessions');
        $response = $this->submitLivewireLogin()->assertStatus(500)->assertJsonPath('message', 'No fue posible completar el acceso. Intenta nuevamente.');
        foreach (['CashierChanged!123', 'SQLSTATE', 'auth_access_sessions', 'trace', 'C:\\Repos'] as $secret) {
            $response->assertDontSee($secret);
        }
    }
}
