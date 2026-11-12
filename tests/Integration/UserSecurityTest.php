<?php

namespace Tests\Integration;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Contracts\TransactionManager;
use Modules\Users\Application\BootstrapAdministrator;
use Modules\Users\Application\PasswordHistoryService;
use Modules\Users\Application\Ports\SecurityStore;
use Modules\Users\Contracts\UserAdministration;
use Modules\Users\Database\Seeders\SecuritySeeder;
use Modules\Users\Domain\SecurityConflict;
use Modules\Users\Infrastructure\Persistence\Models\PasswordHistory;
use Modules\Users\Infrastructure\Persistence\Models\Permission;
use Modules\Users\Infrastructure\Persistence\Models\Role;
use Modules\Users\Infrastructure\Persistence\Models\User;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\SqlServerTestCase;

class UserSecurityTest extends SqlServerTestCase
{
    private Migration $securitySchema;

    private Migration $auditSchema;

    private bool $ownsSchema = false;

    private UserAdministration $users;

    private string $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        config(['hashing.bcrypt.rounds' => 4]); // Solo rendimiento de fixtures, no configuración operativa.
        $this->securitySchema = require base_path('Modules/Users/database/migrations/2026_11_11_000001_create_user_security_tables.php');
        $this->auditSchema = require base_path('Modules/Audit/database/migrations/2026_11_11_000003_create_security_events.php');
        $this->database->transaction(function (): void {
            $this->securitySchema->up();
            $this->auditSchema->up();
        });
        $this->ownsSchema = true;
        try {
            app(SecuritySeeder::class)->run();
            $this->adminId = app(BootstrapAdministrator::class)->create('Administrador de prueba', 'test.admin', 'TestAdmin!123');
            $this->users = app(UserAdministration::class);
        } catch (\Throwable $exception) {
            $this->auditSchema->down();
            $this->securitySchema->down();
            $this->ownsSchema = false;
            throw $exception;
        }
    }

    protected function tearDown(): void
    {
        try {
            while ($this->database->transactionLevel() > 0) {
                $this->database->rollBack();
            }
            if ($this->ownsSchema) {
                $this->auditSchema->down();
                $this->securitySchema->down();
            }
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        } finally {
            parent::tearDown();
        }
    }

    private function createUser(string $username = 'test.cashier'): string
    {
        return $this->users->create($this->adminId, ['name' => '  Persona de prueba  ', 'username' => $username, 'password' => 'Employee!123'])->id;
    }

    public function test_creation_normalization_hashes_ulids_and_safe_serialization(): void
    {
        $id = $this->createUser('  Test.Cashier  ');
        $user = User::query()->findOrFail($id);
        $this->assertTrue(Str::isUlid($id));
        $this->assertSame('test.cashier', $user->username);
        $this->assertSame('Persona de prueba', $user->name);
        $this->assertTrue($user->is_active);
        $this->assertTrue($user->must_change_password);
        $this->assertTrue(Hash::check('Employee!123', $user->password));
        $this->assertNotSame('Employee!123', $user->password);
        $this->assertArrayNotHasKey('password', $user->toArray());
        $this->assertArrayNotHasKey('remember_token', $user->toArray());
        $this->assertStringNotContainsString($user->password, json_encode($this->users->find($this->adminId, $id), JSON_THROW_ON_ERROR));
        $this->assertCount(1, PasswordHistory::query()->where('user_id', $id)->get());
        $this->assertSame(User::class, config('auth.providers.users.model'));
        $this->assertCount(2, $this->users->list($this->adminId));
    }

    public function test_duplicate_and_soft_deleted_usernames_remain_reserved(): void
    {
        $id = $this->createUser();
        $this->assertValidation(fn () => $this->createUser(' TEST.CASHIER '));
        $this->users->delete($this->adminId, $id);
        $this->assertNotNull(User::withTrashed()->findOrFail($id)->deleted_at);
        $this->assertValidation(fn () => $this->createUser('test.cashier'));
        $this->assertSame(2, User::withTrashed()->count());
    }

    public function test_database_rejects_duplicate_and_non_normalized_names_without_application_validation(): void
    {
        $id = $this->createUser();
        $attributes = $this->database->table('users')->where('id', $id)->first();
        foreach (['test.cashier', 'TEST.CASHIER', ' test.cashier', 'test.cashier ', 'bad user', 'ab'] as $name) {
            $row = (array) $attributes;
            $row['id'] = strtolower((string) Str::ulid());
            $row['username'] = $name;
            $this->assertSqlRejected(fn () => $this->database->table('users')->insert($row));
        }
        $this->assertSame(2, User::query()->count());
    }

    public function test_update_activation_and_mass_assignment_boundaries(): void
    {
        $id = $this->createUser();
        $before = User::query()->findOrFail($id)->password;
        $snapshot = $this->users->update($this->adminId, $id, ['name' => '  Nuevo nombre ', 'username' => ' NEW.NAME ', 'is_active' => false, 'password' => 'Unwanted!123']);
        $this->assertSame('Nuevo nombre', $snapshot->name);
        $this->assertSame('new.name', $snapshot->username);
        $this->assertTrue($snapshot->isActive);
        $this->assertSame($before, User::query()->findOrFail($id)->password);
        $this->assertFalse($this->users->setActive($this->adminId, $id, false)->isActive);
        $this->assertTrue($this->users->setActive($this->adminId, $id, true)->isActive);
    }

    public function test_weak_password_is_rejected_and_creation_rolls_back(): void
    {
        $this->assertValidation(fn () => $this->users->create($this->adminId, ['name' => 'Invalid', 'username' => 'invalid.user', 'password' => 'weak']));
        $this->assertSame(1, User::query()->count());
        $this->assertSame(1, PasswordHistory::query()->count());
    }

    public function test_password_history_rejects_last_three_and_retains_only_hashes(): void
    {
        $id = $this->createUser();
        $passwords = app(PasswordHistoryService::class);
        $replace = fn (string $value) => app(TransactionManager::class)->run(fn () => $passwords->replace(User::query()->findOrFail($id), $value));
        $replace('SecondPass!123');
        $replace('ThirdPass!123');
        foreach (['Employee!123', 'SecondPass!123', 'ThirdPass!123'] as $value) {
            $this->assertValidation(fn () => $replace($value));
        }
        $replace('FourthPass!123');
        $replace('Employee!123'); // Ya quedó fuera de las tres últimas.
        $this->assertSame(3, PasswordHistory::query()->where('user_id', $id)->count());
        foreach (PasswordHistory::query()->where('user_id', $id)->get() as $history) {
            $this->assertStringStartsWith('$2y$', $history->password_hash);
            $this->assertArrayNotHasKey('password_hash', $history->toArray());
        }
        $this->assertTrue(Hash::check('Employee!123', User::query()->findOrFail($id)->password));
    }

    public function test_roles_effective_permissions_and_real_permission_policies(): void
    {
        $id = $this->createUser();
        $assigned = $this->users->assignRole($this->adminId, $id, 'cashier');
        $this->assertSame(['cashier'], $assigned->roles);
        $this->assertContains('cash.open', $this->users->permissions($this->adminId, $id));
        $this->assertNotContains('roles.permissions.manage', $assigned->permissions);
        $this->users->assignRole($this->adminId, $id, 'cashier');
        $this->assertSame(1, $this->database->table('model_has_roles')->where('model_id', $id)->count());
        $this->assertSame([], $this->users->removeRole($this->adminId, $id, 'cashier')->roles);
        $this->users->createRole($this->adminId, 'auditor', 'Auditor');
        $this->users->setRolePermissions($this->adminId, 'auditor', ['users.view', 'audit.view']);
        $this->users->assignRole($this->adminId, $id, 'auditor');
        $this->assertSame(['audit.view', 'users.view'], $this->users->permissions($this->adminId, $id));
        $this->assertSame($id, $this->users->find($id, $id)->id);
        $this->assertCount(5, $this->users->roles($this->adminId));
    }

    public function test_unauthorized_and_inactive_users_are_denied_without_any_admin_bypass(): void
    {
        $id = $this->createUser();
        $this->users->assignRole($this->adminId, $id, 'supervisor');
        $this->assertDenied(fn () => $this->users->create($id, ['name' => 'Escalation', 'username' => 'escalation', 'password' => 'Escalation!123']));
        $this->assertDenied(fn () => $this->users->assignRole($id, $id, 'admin'));
        $this->assertDenied(fn () => $this->users->setRolePermissions($id, 'supervisor', ['roles.permissions.manage']));
        $this->users->assignRole($this->adminId, $id, 'admin');
        $this->users->setActive($this->adminId, $id, false);
        $this->assertDenied(fn () => $this->users->list($id));
        $this->assertSame([], $this->users->permissions($this->adminId, $id));
        $this->assertFalse(Gate::forUser(User::query()->findOrFail($id))->allows('users.view'));
        $this->assertSame(2, User::query()->count());
    }

    public function test_last_active_admin_is_preserved_on_deactivation_deletion_and_role_removal(): void
    {
        foreach ([
            fn () => $this->users->setActive($this->adminId, $this->adminId, false),
            fn () => $this->users->delete($this->adminId, $this->adminId),
            fn () => $this->users->removeRole($this->adminId, $this->adminId, 'admin'),
            fn () => $this->users->setRolePermissions($this->adminId, 'admin', ['users.view']),
            fn () => $this->users->setRolePermissions($this->adminId, ' ADMIN ', ['users.view']),
            fn () => User::query()->findOrFail($this->adminId)->forceDelete(),
            fn () => User::query()->findOrFail($this->adminId)->forceDeleteQuietly(),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Debe conservarse un administrador activo con capacidad administrativa.');
            } catch (SecurityConflict) {
                $this->assertTrue(User::query()->findOrFail($this->adminId)->is_active);
                $this->assertTrue(User::query()->findOrFail($this->adminId)->hasRole('admin'));
                $this->assertTrue(Gate::forUser(User::query()->findOrFail($this->adminId))->allows('roles.permissions.manage'));
            }
        }
    }

    public function test_another_admin_allows_safe_deactivation_and_soft_deletion_without_destroying_history(): void
    {
        $id = $this->createUser();
        $this->users->assignRole($this->adminId, $id, 'admin');
        $this->users->setActive($this->adminId, $this->adminId, false);
        $this->users->delete($id, $this->adminId);
        $this->assertTrue(User::withTrashed()->findOrFail($this->adminId)->trashed());
        $this->assertSame(1, PasswordHistory::query()->where('user_id', $this->adminId)->count());
        $this->assertSame(1, $this->database->table('model_has_roles')->where('model_id', $this->adminId)->count());
        $this->expectException(SecurityConflict::class);
        User::withTrashed()->findOrFail($this->adminId)->forceDelete();
    }

    public function test_seed_is_idempotent_and_preserves_customized_assignments_and_credentials(): void
    {
        $this->users->setRolePermissions($this->adminId, 'cashier', ['tickets.view']);
        $hash = User::query()->findOrFail($this->adminId)->password;
        $permissions = Permission::query()->count();
        app(SecuritySeeder::class)->run();
        app(SecuritySeeder::class)->run();
        $this->assertSame(4, Role::query()->count());
        $this->assertSame($permissions, Permission::query()->count());
        $this->assertSame(['tickets.view'], Role::findByName('cashier', 'web')->permissions->pluck('name')->all());
        $this->assertSame(1, User::query()->count());
        $this->assertSame($hash, User::query()->findOrFail($this->adminId)->password);
        $this->assertSame('Administrador', Role::findByName('admin', 'web')->label);
        $this->assertSame([], array_filter(config('users.security.permissions'), fn ($name) => preg_match('/inventory|ingredient|recipe|waste|return|refund/', $name)));
    }

    public function test_bootstrap_cannot_overwrite_existing_users(): void
    {
        $this->expectException(SecurityConflict::class);
        app(BootstrapAdministrator::class)->create('Another', 'another.admin', 'AnotherAdmin!123');
    }

    public function test_admin_role_does_not_bypass_a_removed_permission(): void
    {
        $permissions = array_values(array_diff(config('users.security.permissions'), ['cash.administrative.execute']));
        $this->users->setRolePermissions($this->adminId, 'admin', $permissions);
        $actor = User::query()->findOrFail($this->adminId);
        $this->assertTrue($actor->hasRole('admin'));
        $this->assertFalse(Gate::forUser($actor)->allows('cash.administrative.execute'));
        $this->assertTrue(Gate::forUser($actor)->allows('users.create'));
    }

    public function test_native_spatie_creation_and_existing_schema_preflight_are_safe(): void
    {
        $role = Role::create(['name' => 'observer', 'guard_name' => 'web']);
        $this->assertTrue(Str::isUlid($role->getKey()));
        $this->assertSame('observer', $role->label);
        try {
            $this->securitySchema->up();
            $this->fail('Una tabla existente debe detener la migración.');
        } catch (\RuntimeException) {
            $this->assertSame(1, User::query()->count());
            $this->assertSame(5, Role::query()->count());
        }
    }

    public function test_sql_server_ulids_foreign_keys_and_no_cascades(): void
    {
        foreach (['users', 'roles', 'permissions', 'user_password_histories'] as $table) {
            $column = $this->database->selectOne("SELECT TYPE_NAME(user_type_id) AS type_name, max_length FROM sys.columns WHERE object_id = OBJECT_ID(?) AND name = 'id'", [$table]);
            $this->assertSame('nchar', $column->type_name);
            $this->assertSame(52, (int) $column->max_length);
        }
        foreach (Role::query()->get() as $role) {
            $this->assertTrue(Str::isUlid($role->getKey()));
        }
        $role = Role::findByName('cashier', 'web');
        $this->assertSqlRejected(fn () => $this->database->table('model_has_roles')->insert(['role_id' => $role->getKey(), 'model_id' => strtolower((string) Str::ulid()), 'model_type' => 'users.user']));
        $this->assertSqlRejected(fn () => $this->database->table('users')->where('id', $this->adminId)->delete());
        $constraints = $this->database->select("SELECT delete_referential_action_desc AS action FROM sys.foreign_keys WHERE parent_object_id IN (OBJECT_ID('model_has_roles'), OBJECT_ID('model_has_permissions'), OBJECT_ID('role_has_permissions'), OBJECT_ID('user_password_histories'))");
        $this->assertCount(7, $constraints);
        foreach ($constraints as $constraint) {
            $this->assertSame('NO_ACTION', $constraint->action);
        }
    }

    public function test_denial_and_conflict_use_existing_api_contract_without_permanent_endpoints(): void
    {
        $id = $this->createUser();
        Route::get('api/v1/_user-test/denied', fn () => $this->users->list($id));
        Route::get('api/v1/_user-test/conflict', fn () => $this->users->removeRole($this->adminId, $this->adminId, 'admin'));
        $this->getJson('/api/v1/_user-test/denied')->assertForbidden()->assertJsonPath('code', 'FORBIDDEN')->assertJsonMissingPath('data');
        $this->getJson('/api/v1/_user-test/conflict')->assertConflict()->assertJsonPath('code', 'CONFLICT')->assertDontSee('administrador activo');
    }

    public function test_security_lock_requires_external_transaction_and_serializes_other_sql_connection(): void
    {
        $this->database->beginTransaction();
        app(SecurityStore::class)->lock();
        // Segunda sesión real, sin credenciales nuevas ni otra base.
        $configuration = $this->database->getConfig();
        config(['database.connections.security_probe' => $configuration]);
        $other = app('db')->connection('security_probe');
        try {
            // SET y SELECT en el mismo batch: MARS no conserva el SET entre batches hermanos.
            $other->transaction(fn () => $other->statement('SET LOCK_TIMEOUT 500; SELECT id FROM user_security_locks WITH (UPDLOCK, HOLDLOCK) WHERE id = 1'));
            $this->fail('La segunda sesión debía esperar el lock de seguridad.');
        } catch (QueryException $exception) {
            $this->assertSame(1222, (int) $exception->errorInfo[1]);
        } finally {
            app('db')->purge('security_probe');
            $this->database->rollBack();
        }
        $this->expectException(\LogicException::class);
        app(SecurityStore::class)->lock();
    }

    private function assertValidation(Closure $operation): void
    {
        try {
            $operation();
            $this->fail('Se esperaba validación.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
    }

    private function assertDenied(Closure $operation): void
    {
        try {
            $operation();
            $this->fail('Se esperaba denegación.');
        } catch (AuthorizationException) {
            $this->assertTrue(true);
        }
    }

    private function assertSqlRejected(Closure $operation): void
    {
        try {
            $operation();
            $this->fail('SQL Server debía rechazar la escritura.');
        } catch (QueryException $exception) {
            $this->assertContains((int) $exception->errorInfo[1], [2601, 2627, 547]);
        }
    }
}
