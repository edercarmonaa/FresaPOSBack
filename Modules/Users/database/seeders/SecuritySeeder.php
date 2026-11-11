<?php

namespace Modules\Users\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Contracts\TransactionManager;
use Modules\Users\Application\Ports\SecurityStore;
use Modules\Users\Infrastructure\Persistence\Models\Permission;
use Modules\Users\Infrastructure\Persistence\Models\Role;
use Spatie\Permission\PermissionRegistrar;

final class SecuritySeeder extends Seeder
{
    public function run(): void
    {
        app(TransactionManager::class)->run(function (): void {
            app(SecurityStore::class)->lock();
            app(PermissionRegistrar::class)->forgetCachedPermissions();
            foreach (config('users.security.permissions') as $name) {
                Permission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web']);
            }
            foreach (config('users.security.initial_roles') as $name => $permissions) {
                $role = Role::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web'], ['label' => config('users.security.labels.'.$name)]);
                // Preservar configuraciones administrativas al repetir el seeding.
                if ($role->wasRecentlyCreated) {
                    $role->syncPermissions(Permission::query()->where('guard_name', 'web')->whereIn('name', $permissions ?? config('users.security.permissions'))->get());
                }
            }
            app(TransactionManager::class)->afterCommit(fn () => app(PermissionRegistrar::class)->forgetCachedPermissions());
        });
    }
}
