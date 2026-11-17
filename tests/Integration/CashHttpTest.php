<?php

namespace Tests\Integration;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Modules\Auth\Infrastructure\Models\AccessSession;
use Modules\Cash\Application\Services\ProvisionCashRegister;
use Modules\Cash\Contracts\CashOperations;
use Modules\Cash\Http\Livewire\CashDesk;
use Modules\Sales\Http\Livewire\PointOfSale;
use Modules\Users\Application\BootstrapAdministrator;
use Modules\Users\Contracts\IdentityAccess;
use Modules\Users\Contracts\UserAdministration;
use Tests\Support\CashSqlServerTestCase;

class CashHttpTest extends CashSqlServerTestCase
{
    private string $actor;

    private string $register;

    private string $token;

    private array $cookies = [];

    private static array $samples = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = app(BootstrapAdministrator::class)->create('Cash HTTP fixture', 'cash.http', 'CashInitial!123');
        app(IdentityAccess::class)->changePassword($this->actor, 'CashInitial!123', 'CashChanged!123');
        $this->register = app(ProvisionCashRegister::class)->run()->id;
        $this->token = $this->loginApi();
    }

    public static function tearDownAfterClass(): void
    {
        file_put_contents(dirname(__DIR__, 2).'/.tools/cash-1.5d1-contract-responses.json', json_encode(self::$samples, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        self::$samples = [];
        parent::tearDownAfterClass();
    }

    private function loginApi(string $username = 'cash.http', string $password = 'CashChanged!123'): string
    {
        Auth::forgetGuards();

        return $this->postJson('/api/v1/auth/login', compact('username', 'password'))->assertOk()->json('data.token');
    }

    private function api(string $method, string $path, array $data = [], ?string $key = null, ?string $token = null): TestResponse
    {
        Auth::forgetGuards();
        foreach (app('router')->getRoutes() as $route) {
            $route->flushController();
        }
        $response = $this->json($method, '/api/v1/cash'.$path, $data, ['Authorization' => 'Bearer '.($token ?? $this->token),
            'X-Request-Id' => 'cash-http', ...($key === null ? [] : ['Idempotency-Key' => $key])]);
        $response->assertHeader('X-Request-Id', 'cash-http')->assertJsonPath('meta.request_id', 'cash-http');
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::$samples[] = ['method' => strtolower($method), 'path' => '/cash'.$path, 'status' => $response->status(), 'body' => $response->json()];

        return $response;
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

    private function loginWeb(string $username = 'cash.http', string $password = 'CashChanged!123'): void
    {
        $this->web('POST', '/login', compact('username', 'password'))->assertRedirect(route('auth.account'));
        $this->web('GET', '/account')->assertOk();
    }

    public function test_api_list_current_open_and_durable_replay(): void
    {
        $this->api('GET', '/registers')->assertOk()->assertJsonPath('data.registers.0.id', $this->register);
        $this->api('GET', '/sessions/current')->assertOk()->assertJsonPath('data.session', null);
        $data = ['register_id' => $this->register, 'opening_fund' => '125.50'];
        $a = $this->api('POST', '/sessions', $data, 'cash-http-open-123456')->assertOk()->assertJsonPath('data.session.opening_fund', '125.5000');
        $b = $this->api('POST', '/sessions', $data, 'cash-http-open-123456')->assertOk();
        $this->assertSame($a->json('data'), $b->json('data'));
        $this->api('GET', '/sessions/current')->assertOk()->assertJsonPath('data.session.id', $a->json('data.session.id'));
        $this->api('POST', '/sessions', $data, 'cash-http-other-123456')->assertConflict();
        $this->api('POST', '/sessions', ['register_id' => $this->register, 'opening_fund' => '126'], 'cash-http-open-123456')->assertConflict();
        $this->assertSame(1, $this->database->table('cash_sessions')->count());
        $this->assertSame(1, $this->database->table('audit_security_events')->where('event_type', 'cash.session.opened')->count());
        foreach (['opening_key_hash', 'opening_fingerprint', 'password', 'connection', 'trace'] as $secret) {
            $this->assertStringNotContainsString($secret, $a->getContent());
        }
    }

    public function test_api_validation_unknown_fields_missing_inactive_register_and_no_side_effects(): void
    {
        $valid = ['register_id' => $this->register, 'opening_fund' => '0'];
        foreach (['-1', '1.12345', '1e3', 1.5, 0, true, '9999999999999999', '<script>'] as $fund) {
            $this->api('POST', '/sessions', ['register_id' => $this->register, 'opening_fund' => $fund], 'cash-http-open-123456')->assertUnprocessable()->assertJsonPath('code', 'VALIDATION_ERROR');
        }
        $this->api('POST', '/sessions', $valid)->assertUnprocessable();
        $this->api('POST', '/sessions', $valid + ['opened_by' => $this->actor], 'cash-http-open-123456')->assertUnprocessable();
        $this->api('GET', '/sessions/current', ['actor_id' => $this->actor])->assertUnprocessable();
        $this->api('POST', '/sessions', ['register_id' => strtolower((string) Str::ulid()), 'opening_fund' => '0'], 'cash-http-open-123456')->assertNotFound();
        app(CashOperations::class)->setRegisterActive($this->actor, $this->register, false);
        $this->api('POST', '/sessions', $valid, 'cash-http-open-123456')->assertConflict();
        $this->assertSame(0, $this->database->table('cash_sessions')->count());
    }

    public function test_api_permissions_revoked_session_token_and_inactive_user(): void
    {
        Auth::forgetGuards();
        $this->getJson('/api/v1/cash/registers')->assertUnauthorized();
        $users = app(UserAdministration::class);
        $id = $users->create($this->actor, ['name' => 'Cash employee', 'username' => 'cash.employee', 'password' => 'EmployeeInitial!123'])->id;
        $users->assignRole($this->actor, $id, 'waiter');
        app(IdentityAccess::class)->changePassword($id, 'EmployeeInitial!123', 'EmployeeChanged!123');
        $token = $this->loginApi('cash.employee', 'EmployeeChanged!123');
        $this->api('GET', '/sessions/current', token: $token)->assertForbidden();
        $this->api('POST', '/sessions', ['register_id' => $this->register, 'opening_fund' => '0'], 'cash-http-open-123456', $token)->assertForbidden();
        $users->assignRole($this->actor, $id, 'cashier');
        $this->api('GET', '/registers', token: $token)->assertOk();
        AccessSession::query()->where('user_id', $id)->update(['revoked_at' => now()]);
        $this->api('GET', '/registers', token: $token)->assertUnauthorized();
        $token = $this->loginApi('cash.employee', 'EmployeeChanged!123');
        $this->database->table('personal_access_tokens')->where('token', hash('sha256', explode('|', $token)[1]))->delete();
        $this->api('GET', '/registers', token: $token)->assertUnauthorized();
        $token = $this->loginApi('cash.employee', 'EmployeeChanged!123');
        $users->setActive($this->actor, $id, false);
        $this->api('GET', '/registers', token: $token)->assertUnauthorized();
        $this->assertSame(0, $this->database->table('cash_sessions')->count());
    }

    public function test_livewire_open_validation_repeated_submit_status_and_pos(): void
    {
        $this->web('GET', '/cash')->assertRedirect(route('login'));
        $this->loginWeb();
        $this->web('GET', '/account')->assertSee(route('cash.desk'));
        $this->web('GET', '/cash')->assertOk()->assertSee('Sin sesión abierta')->assertSee('Confirmar apertura de caja');
        Livewire::test(CashDesk::class)->set('openingFund', '-2')->call('openSession')->assertHasErrors('opening_fund')->assertSet('openingFund', '-2');
        $component = Livewire::test(CashDesk::class)->set('openingFund', '10.25')->call('openSession')->assertHasNoErrors()->assertSee('Sesión de caja abierta correctamente')->assertSee('10.2500');
        $component->call('openSession')->assertHasNoErrors()->call('refreshState')->assertSee('Abierta');
        $this->assertSame(1, $this->database->table('cash_sessions')->count());
        Livewire::test(PointOfSale::class)->assertSee('Sesión de caja activa')->assertSee('idempotencia durable')
            ->call('startSale')->assertHasErrors('operation');
        Auth::forgetGuards();
        $this->withToken($this->token)->withHeader('Idempotency-Key', (string) Str::uuid())->postJson('/api/v1/sales', ['type' => 'counter'])->assertStatus(503);
        $this->assertSame(0, $this->database->table('sales_sales')->count());
    }

    public function test_web_livewire_revoked_session_and_locked_context(): void
    {
        $this->loginWeb();
        $page = $this->web('GET', '/cash')->assertOk();
        $dom = new DOMDocument;
        $dom->loadHTML($page->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        $element = (new DOMXPath($dom))->query('//*[@*[name()="wire:snapshot"]]')->item(0);
        $snapshot = $element->getAttribute('wire:snapshot');
        preg_match('/data-update-uri="([^"]+)"/', $page->getContent(), $matches);
        AccessSession::query()->where('channel', 'web')->where('user_id', $this->actor)->update(['revoked_at' => now()]);
        $payload = ['_token' => session()->token(), 'components' => [['snapshot' => $snapshot, 'updates' => ['openingFund' => '9'], 'calls' => [['path' => '', 'method' => 'openSession', 'params' => []]]]]];
        $this->web('POST', html_entity_decode($matches[1]), $payload, true)->assertUnauthorized();
        $this->assertSame(0, $this->database->table('cash_sessions')->count());
        $this->loginWeb();
        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(CashDesk::class)->set('actorContext', strtolower((string) Str::ulid()));
    }

    public function test_cash_errors_do_not_leak_internal_details_in_both_debug_modes(): void
    {
        $this->loginWeb();
        foreach ([true, false] as $debug) {
            config(['app.debug' => $debug]);
            $this->app->bind(CashOperations::class, fn () => throw new \RuntimeException('secret-sql-server-password-C:\\private'));
            $this->api('GET', '/registers')->assertStatus(500)->assertJsonPath('code', 'INTERNAL_ERROR')->assertDontSee('secret-sql');
            $this->web('GET', '/cash')->assertStatus(500)->assertDontSee('secret-sql');
        }
    }

    public function test_livewire_rechecks_open_permission_and_rejects_other_employee_snapshot(): void
    {
        $users = app(UserAdministration::class);
        $id = $users->create($this->actor, ['name' => 'Cash cashier', 'username' => 'cash.cashier', 'password' => 'EmployeeInitial!123'])->id;
        $users->assignRole($this->actor, $id, 'cashier');
        app(IdentityAccess::class)->changePassword($id, 'EmployeeInitial!123', 'EmployeeChanged!123');
        $this->loginWeb('cash.cashier', 'EmployeeChanged!123');
        $component = Livewire::test(CashDesk::class)->set('openingFund', '777.1234');
        $users->setRolePermissions($this->actor, 'cashier', ['cash.view']);
        $component->call('openSession')->assertForbidden();
        $this->assertSame(0, $this->database->table('cash_sessions')->count());
        Livewire::test(CashDesk::class)->assertDontSee('Confirmar apertura de caja');
        $users->setRolePermissions($this->actor, 'cashier', []);
        $this->web('GET', '/cash')->assertForbidden()->assertDontSee('777.1234');
        $this->loginWeb();
        $old = Livewire::test(CashDesk::class);
        $this->loginWeb('cash.cashier', 'EmployeeChanged!123');
        $users->setRolePermissions($this->actor, 'cashier', ['cash.view', 'cash.open']);
        $old->call('openSession')->assertForbidden();
        $this->assertSame(0, $this->database->table('cash_sessions')->count());
    }
}
