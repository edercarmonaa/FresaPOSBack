<?php

namespace Tests\Integration;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Modules\Auth\Application\AttemptGuard;
use Modules\Auth\Application\SessionRegistry;
use Modules\Auth\Http\Livewire\Sessions;
use Modules\Auth\Infrastructure\Models\AccessSession;
use Modules\Auth\Infrastructure\Models\AccessToken;
use Modules\Auth\Infrastructure\Models\EmployeePin;
use Modules\Core\Contracts\SecurityEvent;
use Modules\Core\Contracts\TransactionManager;
use Modules\Users\Application\BootstrapAdministrator;
use Modules\Users\Contracts\IdentityAccess;
use Modules\Users\Contracts\UserAdministration;
use Modules\Users\Infrastructure\Persistence\Models\User;
use Tests\Support\AuthSqlServerTestCase;
use Tests\Support\TestDatabaseGuard;

class AdvancedSecurityTest extends AuthSqlServerTestCase
{
    private string $admin;

    private string $employee;

    private array $cookies = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = app(BootstrapAdministrator::class)->create('Test admin', 'security.admin', 'AdminInitial!123');
        app(IdentityAccess::class)->changePassword($this->admin, 'AdminInitial!123', 'AdminChanged!123');
        $this->employee = app(UserAdministration::class)->create($this->admin, ['name' => 'Test employee', 'username' => 'security.employee', 'password' => 'EmployeeInitial!123'])->id;
        app(UserAdministration::class)->assignRole($this->admin, $this->employee, 'cashier');
        app(IdentityAccess::class)->changePassword($this->employee, 'EmployeeInitial!123', 'EmployeeChanged!123');
    }

    private function api(string $method, string $uri, array $data = [], ?string $token = null): TestResponse
    {
        Auth::forgetGuards();

        return $this->json($method, $uri, $data, $token ? ['Authorization' => 'Bearer '.$token] : []);
    }

    private function login(bool $admin = false): string
    {
        return $this->api('POST', '/api/v1/auth/login', [
            'username' => $admin ? 'security.admin' : 'security.employee',
            'password' => $admin ? 'AdminChanged!123' : 'EmployeeChanged!123',
        ])->assertOk()->json('data.token');
    }

    private function web(string $method, string $uri, array $data = [], bool $livewire = false): TestResponse
    {
        Auth::forgetGuards();
        Auth::shouldUse('web');
        $response = $livewire
            ? $this->call($method, $uri, [], $this->cookies, [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_LIVEWIRE' => 'true'], json_encode($data, JSON_THROW_ON_ERROR))
            : $this->call($method, $uri, $data, $this->cookies, [], ['HTTP_ACCEPT' => 'text/html']);
        foreach ($response->headers->getCookies() as $cookie) {
            $this->cookies[$cookie->getName()] = $cookie->getValue();
        }

        return $response;
    }

    private function webLogin(): void
    {
        $this->web('POST', '/login', ['username' => 'security.employee', 'password' => 'EmployeeChanged!123'])->assertRedirect(route('auth.account'));
    }

    public function test_fifth_failure_locks_for_full_five_minutes_and_unlocks_without_real_waits(): void
    {
        $credentials = ['username' => 'security.employee', 'password' => 'incorrect'];
        for ($i = 0; $i < 4; $i++) {
            $this->api('POST', '/api/v1/auth/login', $credentials)->assertUnauthorized();
        }
        $this->travel(100)->seconds();
        $this->api('POST', '/api/v1/auth/login', $credentials)->assertUnauthorized();
        $this->api('POST', '/api/v1/auth/login', $credentials)->assertStatus(429)->assertHeader('Retry-After');
        $this->travel(299)->seconds();
        $this->api('POST', '/api/v1/auth/login', $credentials)->assertStatus(429)->assertJsonPath('code', 'TOO_MANY_REQUESTS');
        $this->travel(2)->seconds();
        $this->login();
        $this->assertSame(1, DB::table('audit_security_events')->where('event_type', 'password.locked')->count());
        $this->assertSame(1, DB::table('audit_security_events')->where('event_type', 'authentication.unlocked')->count());
    }

    public function test_success_resets_consecutive_failures_and_origin_isolation_prevents_account_wide_lockout(): void
    {
        $guard = app(AttemptGuard::class);
        $first = $guard->key('login', 'Security.Employee', '192.0.2.1');
        $second = $guard->key('login', 'security.employee', '192.0.2.2');
        for ($i = 0; $i < 5; $i++) {
            $guard->failed($first, 'password');
        }
        $guard->check($second);
        $this->assertNotSame($first, $second);
        $credentials = ['username' => 'security.employee', 'password' => 'wrong'];
        for ($i = 0; $i < 4; $i++) {
            $this->api('POST', '/api/v1/auth/login', $credentials)->assertUnauthorized();
        }
        $this->login();
        for ($i = 0; $i < 4; $i++) {
            $this->api('POST', '/api/v1/auth/login', $credentials)->assertUnauthorized();
        }
        $this->login();
    }

    public function test_pin_lock_survives_new_browser_login_and_expires_after_five_minutes(): void
    {
        $this->webLogin();
        $this->web('POST', '/auth/pin', ['current_password' => 'EmployeeChanged!123', 'pin' => '0042', 'pin_confirmation' => '0042'])->assertRedirect();
        $this->web('POST', '/auth/terminal', ['current_password' => 'EmployeeChanged!123'])->assertRedirect();
        for ($i = 0; $i < 5; $i++) {
            $this->web('POST', '/auth/employee', ['username' => 'security.employee', 'pin' => '1111'])->assertSessionHasErrors('pin');
        }
        $this->cookies = [];
        $this->webLogin();
        $this->web('POST', '/auth/terminal', ['current_password' => 'EmployeeChanged!123'])->assertRedirect();
        $this->web('POST', '/auth/employee', ['username' => 'security.employee', 'pin' => '0042'])->assertStatus(429);
        $this->travel(301)->seconds();
        $this->web('POST', '/auth/employee', ['username' => 'security.employee', 'pin' => '0042'])->assertRedirect(route('auth.account'));
        $this->assertSame(1, DB::table('audit_security_events')->where('event_type', 'pin.locked')->count());
    }

    public function test_admin_remote_web_revocation_is_effective_on_next_protected_request(): void
    {
        $this->webLogin();
        $record = AccessSession::query()->where('user_id', $this->employee)->firstOrFail();
        $token = $this->login(true);
        $this->api('DELETE', '/api/v1/auth/sessions/'.$record->id, [], $token)->assertOk();
        $this->web('GET', '/account')->assertRedirect(route('login'));
        $this->assertSame($this->admin, DB::table('audit_security_events')->where('event_type', 'session.revoked')->where('record_id', $record->id)->value('actor_id'));
    }

    public function test_api_own_list_revocation_and_idempotence_hide_reusable_identifiers(): void
    {
        $first = $this->login();
        $second = $this->login();
        $record = AccessSession::query()->where('token_id', explode('|', $first)[0])->firstOrFail();
        $response = $this->api('GET', '/api/v1/auth/sessions', [], $second)->assertOk();
        $this->assertSame($response->headers->get('X-Request-Id'), $response->json('meta.request_id'));
        foreach (['token_id', 'session_hash', 'token', 'ip_address'] as $field) {
            $response->assertJsonMissingPath('data.sessions.0.'.$field);
        }
        for ($i = 0; $i < 2; $i++) {
            $this->api('DELETE', '/api/v1/auth/sessions/'.$record->id, [], $second)->assertOk();
        }
        $this->api('GET', '/api/v1/auth/me', [], $first)->assertUnauthorized();
        $this->api('GET', '/api/v1/auth/me', [], $second)->assertOk();
    }

    public function test_horizontal_access_is_denied_for_read_single_revoke_and_bulk_revoke(): void
    {
        $admin = $this->login(true);
        $foreign = AccessSession::query()->where('user_id', $this->admin)->firstOrFail();
        $employee = $this->login();
        $this->api('GET', '/api/v1/auth/users/'.$this->admin.'/sessions', [], $employee)->assertForbidden();
        $this->api('DELETE', '/api/v1/auth/users/'.$this->admin.'/sessions', [], $employee)->assertForbidden();
        $this->api('DELETE', '/api/v1/auth/sessions/'.$foreign->id, [], $employee)->assertForbidden();
        $this->api('DELETE', '/api/v1/auth/sessions/invalid-id', [], $employee)->assertNotFound();
        $this->api('GET', '/api/v1/auth/me', [], $admin)->assertOk();
    }

    public function test_admin_bulk_revocation_closes_web_and_all_tokens_without_automatic_recovery(): void
    {
        $this->webLogin();
        $first = $this->login();
        $second = $this->login();
        $admin = $this->login(true);
        $uri = '/api/v1/auth/users/'.$this->employee.'/sessions';
        $this->api('GET', $uri, [], $admin)->assertOk()->assertJsonCount(3, 'data.sessions');
        $this->api('DELETE', $uri, [], $admin)->assertOk();
        $this->api('DELETE', $uri, [], $admin)->assertOk();
        $this->api('GET', '/api/v1/auth/me', [], $first)->assertUnauthorized();
        $this->api('GET', '/api/v1/auth/me', [], $second)->assertUnauthorized();
        $this->web('GET', '/account')->assertRedirect(route('login'));
        $this->assertSame(0, AccessSession::query()->where('user_id', $this->employee)->whereNull('revoked_at')->count());
    }

    public function test_revoke_others_preserves_only_current_access_across_channels(): void
    {
        $this->webLogin();
        $first = $this->login();
        $second = $this->login();
        $this->api('DELETE', '/api/v1/auth/sessions', ['others' => true], $second)->assertOk();
        $this->api('GET', '/api/v1/auth/me', [], $first)->assertUnauthorized();
        $this->api('GET', '/api/v1/auth/me', [], $second)->assertOk();
        $this->web('GET', '/account')->assertRedirect(route('login'));
    }

    public function test_limits_release_capacity_after_expiry_and_revocation_without_silent_eviction(): void
    {
        config(['auth_access.max_api_sessions' => 1, 'auth_access.api_token_minutes' => 10]);
        $first = $this->login();
        $this->api('POST', '/api/v1/auth/login', ['username' => 'security.employee', 'password' => 'EmployeeChanged!123'])->assertStatus(429);
        $this->api('GET', '/api/v1/auth/me', [], $first)->assertOk();
        $this->travel(11)->minutes();
        $second = $this->login();
        $this->api('DELETE', '/api/v1/auth/sessions', [], $second)->assertOk();
        $this->login();
    }

    public function test_deactivation_and_reactivation_cannot_restore_existing_web_token_or_pin(): void
    {
        $this->webLogin();
        $this->web('POST', '/auth/pin', ['current_password' => 'EmployeeChanged!123', 'pin' => '0042', 'pin_confirmation' => '0042']);
        $token = $this->login();
        app(UserAdministration::class)->setActive($this->admin, $this->employee, false);
        $this->assertNull(EmployeePin::query()->find($this->employee));
        $this->assertSame(0, AccessToken::query()->where('tokenable_id', $this->employee)->count());
        $this->web('GET', '/account')->assertRedirect(route('login'));
        app(UserAdministration::class)->setActive($this->admin, $this->employee, true);
        $this->api('GET', '/api/v1/auth/me', [], $token)->assertUnauthorized();
        $this->web('GET', '/account')->assertRedirect(route('login'));
        $this->login();
    }

    public function test_soft_delete_preserves_audit_and_disables_issued_access(): void
    {
        $this->webLogin();
        $token = $this->login();
        app(UserAdministration::class)->delete($this->admin, $this->employee);
        $this->assertTrue(User::withTrashed()->findOrFail($this->employee)->trashed());
        $this->api('GET', '/api/v1/auth/me', [], $token)->assertUnauthorized();
        $this->web('GET', '/account')->assertRedirect(route('login'));
        $this->assertTrue(DB::table('audit_security_events')->where('subject_id', $this->employee)->where('event_type', 'user.deleted')->exists());
    }

    public function test_contract_password_change_revokes_all_previous_access_even_outside_http_flow(): void
    {
        $this->webLogin();
        $token = $this->login();
        app(IdentityAccess::class)->changePassword($this->employee, 'EmployeeChanged!123', 'EmployeeNext!123');
        $this->api('GET', '/api/v1/auth/me', [], $token)->assertUnauthorized();
        $this->web('GET', '/account')->assertRedirect(route('login'));
    }

    public function test_role_permission_revocation_applies_to_existing_web_and_api_sessions(): void
    {
        Route::get('/api/v1/_security/cash', fn () => ['ok' => true])->middleware(['api', 'auth:sanctum', 'auth.access.api', 'can:cash.open']);
        $token = $this->login();
        $this->api('GET', '/api/v1/_security/cash', [], $token)->assertOk();
        app(UserAdministration::class)->removeRole($this->admin, $this->employee, 'cashier');
        $this->api('GET', '/api/v1/_security/cash', [], $token)->assertForbidden();
        app(UserAdministration::class)->assignRole($this->admin, $this->employee, 'cashier');
        app(UserAdministration::class)->setRolePermissions($this->admin, 'cashier', ['tickets.view']);
        $this->api('GET', '/api/v1/_security/cash', [], $token)->assertForbidden();
    }

    public function test_audit_failure_rolls_back_user_deactivation_and_revocation_together(): void
    {
        $token = $this->login();
        Event::listen(SecurityEvent::class, function (SecurityEvent $event): void {
            if ($event->type === 'user.deactivated') {
                throw new \RuntimeException('Synthetic audit failure');
            }
        });
        try {
            app(UserAdministration::class)->setActive($this->admin, $this->employee, false);
            $this->fail('Debe propagar el fallo y revertir la transacción.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic audit failure', $exception->getMessage());
        }
        $this->assertTrue(User::query()->findOrFail($this->employee)->is_active);
        $this->api('GET', '/api/v1/auth/me', [], $token)->assertOk();
    }

    public function test_audit_allowlist_records_request_id_and_no_credentials_or_reusable_identifiers(): void
    {
        $token = $this->login();
        $response = $this->api('DELETE', '/api/v1/auth/sessions', [], $token)->assertOk();
        $event = DB::table('audit_security_events')->where('event_type', 'sessions.revoked')->first();
        $this->assertSame('api', $event->client);
        $this->assertSame($response->json('meta.request_id'), $event->request_id);
        $events = json_encode(DB::table('audit_security_events')->get(), JSON_THROW_ON_ERROR);
        foreach (['EmployeeChanged!123', 'EmployeeInitial!123', 'AdminChanged!123', $token, 'Authorization', 'session_hash', 'pin_hash', 'password_hash'] as $secret) {
            $this->assertStringNotContainsString($secret, $events);
        }
        foreach (['actorId', 'subjectId', 'recordId'] as $field) {
            try {
                new SecurityEvent('login', ...[$field => 'NeverStoreCredential!123']);
                $this->fail('Debe rechazar valores arbitrarios en referencias.');
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringNotContainsString('NeverStoreCredential!123', $exception->getMessage());
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        event(new SecurityEvent('unapproved-payload'));
    }

    public function test_livewire_actions_reauthorize_tampered_target_and_require_admin_permission(): void
    {
        $foreign = $this->login(true);
        $record = AccessSession::query()->where('user_id', $this->admin)->firstOrFail();
        $this->webLogin();
        Livewire::test(Sessions::class)->assertSee('Administración de sesiones')->call('revoke', $record->id)->assertForbidden();
        Livewire::test(Sessions::class)->set('userId', $this->admin)->call('consult')->assertForbidden();
        $this->api('GET', '/api/v1/auth/me', [], $foreign)->assertOk();
    }

    public function test_session_issuance_lock_blocks_second_sql_connection_even_with_no_sessions(): void
    {
        $configuration = config('database.connections.sqlsrv_testing');
        config(['database.connections.security_competitor' => $configuration]);
        $competitor = DB::connection('security_competitor');
        TestDatabaseGuard::assertConnected($competitor);
        try {
            app(TransactionManager::class)->run(function () use ($competitor): void {
                app(SessionRegistry::class)->assertCapacity($this->employee, 'api');
                try {
                    $competitor->select('SET LOCK_TIMEOUT 1000; SELECT id FROM user_security_locks WITH (UPDLOCK, HOLDLOCK) WHERE id = 1');
                    $this->fail('La segunda conexión debe quedar excluida.');
                } catch (QueryException $exception) {
                    $this->assertSame(1222, (int) ($exception->errorInfo[1] ?? 0));
                }
            });
        } finally {
            DB::purge('security_competitor');
        }
        $this->login();
    }

    public function test_web_expiration_is_audited_once_and_revoked_access_does_not_reappear(): void
    {
        $this->webLogin();
        $record = AccessSession::query()->where('user_id', $this->employee)->firstOrFail();
        $record->update(['expires_at' => now()->subSecond()]);
        $this->web('GET', '/account')->assertRedirect(route('login'));
        $this->web('GET', '/account')->assertRedirect(route('login'));
        $this->assertSame(1, DB::table('audit_security_events')->where('event_type', 'session.expired')->where('record_id', $record->id)->count());
    }

    public function test_active_web_sessions_lose_permission_without_new_login(): void
    {
        Route::get('/_security/cash', fn () => 'Authorized')->middleware(['web', 'auth.access.web', 'can:cash.open']);
        $this->webLogin();
        $this->web('GET', '/_security/cash')->assertOk();
        app(UserAdministration::class)->setRolePermissions($this->admin, 'cashier', ['tickets.view']);
        $this->web('GET', '/_security/cash')->assertForbidden();
    }

    public function test_livewire_admin_can_consult_and_revoke_the_selected_user_access(): void
    {
        $token = $this->login();
        $this->web('POST', '/login', ['username' => 'security.admin', 'password' => 'AdminChanged!123'])->assertRedirect(route('auth.account'));
        Livewire::test(Sessions::class)
            ->set('userId', $this->employee)->call('consult')->assertSee('Test employee')
            ->call('revokeAll')->assertSet('status', 'Accesos revocados. Es necesario autenticarse nuevamente.');
        $this->api('GET', '/api/v1/auth/me', [], $token)->assertUnauthorized();
    }

    public function test_pin_session_loses_access_when_operational_permissions_are_removed(): void
    {
        $this->webLogin();
        $this->web('POST', '/auth/pin', ['current_password' => 'EmployeeChanged!123', 'pin' => '0042', 'pin_confirmation' => '0042'])->assertRedirect();
        $this->web('POST', '/auth/terminal', ['current_password' => 'EmployeeChanged!123'])->assertRedirect();
        $this->web('POST', '/auth/employee', ['username' => 'security.employee', 'pin' => '0042'])->assertRedirect(route('auth.account'));
        // El propietario conserva cash.open; solo el empleado pierde su alcance operativo.
        $this->database->table('auth_access_sessions')->where('user_id', $this->employee)->whereNull('revoked_at')->update(['terminal_owner_id' => $this->admin]);
        app(UserAdministration::class)->setRolePermissions($this->admin, 'cashier', ['tickets.view']);
        $this->web('GET', '/account')->assertRedirect(route('login'));
    }

    public function test_audit_migration_enforces_sql_constraints_indexes_utc_and_eloquent_round_trip(): void
    {
        $columns = $this->database->getSchemaBuilder()->getColumns('audit_security_events');
        $id = collect($columns)->firstWhere('name', 'id');
        $this->assertSame('nchar', $id['type_name']);
        $physical = $this->database->selectOne("SELECT max_length FROM sys.columns WHERE object_id = OBJECT_ID('audit_security_events') AND name = 'id'");
        $this->assertSame(52, (int) $physical->max_length);
        $foreignKeys = $this->database->getSchemaBuilder()->getForeignKeys('audit_security_events');
        $this->assertCount(2, $foreignKeys);
        foreach ($foreignKeys as $foreignKey) {
            $this->assertSame('users', $foreignKey['foreign_table']);
            $this->assertSame('no action', $foreignKey['on_delete']);
        }
        $indexes = $this->database->getSchemaBuilder()->getIndexes('audit_security_events');
        foreach ([['id'], ['event_type'], ['subject_id'], ['occurred_at'], ['subject_id', 'occurred_at']] as $expected) {
            $this->assertContains($expected, array_column($indexes, 'columns'));
        }
        $this->travelTo(CarbonImmutable::parse('2026-11-12 12:00:00', 'America/Mexico_City'));
        event(new SecurityEvent('login', $this->employee, $this->employee));
        $row = $this->database->table('audit_security_events')->where('event_type', 'login')->first();
        $this->assertSame('2026-11-12 18:00:00', substr($row->occurred_at, 0, 19));
        $model = new class extends Model
        {
            protected $table = 'audit_security_events';

            public $timestamps = false;

            public $incrementing = false;

            protected $keyType = 'string';
        };
        $this->assertSame($this->employee, $model->newQuery()->findOrFail($row->id)->getAttribute('actor_id'));
        $valid = (array) $row;
        foreach ([['actor_id' => strtolower((string) Str::ulid())], ['subject_id' => strtolower((string) Str::ulid())], ['result' => 'unknown'], ['client' => 'unknown']] as $invalid) {
            try {
                $this->database->table('audit_security_events')->insert(array_replace($valid, ['id' => strtolower((string) Str::ulid())], $invalid));
                $this->fail('SQL Server debe rechazar la referencia o valor no permitido.');
            } catch (QueryException $exception) {
                $this->assertSame(547, (int) ($exception->errorInfo[1] ?? 0));
            }
        }
    }

    public function test_real_livewire_update_rejects_a_remotely_revoked_web_session(): void
    {
        $this->webLogin();
        $initial = $this->web('GET', '/account')->assertOk();
        $document = new \DOMDocument;
        $document->loadHTML($initial->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        $snapshot = null;
        foreach ((new \DOMXPath($document))->query('//*[@*[name()="wire:snapshot"]]') as $root) {
            $value = $root->getAttribute('wire:snapshot');
            if (json_decode($value, true, 512, JSON_THROW_ON_ERROR)['memo']['name'] === 'auth.sessions') {
                $snapshot = $value;
                break;
            }
        }
        $this->assertNotNull($snapshot);
        preg_match('/data-update-uri="([^"]+)"/', $initial->getContent(), $matches);
        $record = AccessSession::query()->where('user_id', $this->employee)->firstOrFail();
        app(SessionRegistry::class)->revokeAuthorized($this->admin, $record->id);
        $this->web('POST', html_entity_decode($matches[1]), [
            '_token' => session()->token(),
            'components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => [['path' => '', 'method' => 'consult', 'params' => []]]]],
        ], true)->assertUnauthorized();
    }
}
