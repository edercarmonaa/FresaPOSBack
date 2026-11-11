<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['users', 'roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions', 'user_password_histories', 'user_security_locks'] as $table) {
            if (Schema::hasTable($table)) {
                throw new RuntimeException('Ya existe una tabla de seguridad; revisar e integrar mediante otra migración, sin sobrescribirla.');
            }
        }
        Schema::create('users', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name', 150);
            $table->string('username', 64)->collation('Latin1_General_100_BIN2')->unique();
            $table->string('password', 255);
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('must_change_password')->default(true);
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_username_normalized CHECK (LEN(username) BETWEEN 3 AND 64 AND DATALENGTH(username) = LEN(username) * 2 AND username NOT LIKE '%[^a-z0-9._-]%' COLLATE Latin1_General_100_BIN2)");
        foreach (['roles', 'permissions'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name): void {
                $table->ulid('id')->primary();
                $table->string('name', 100);
                $table->string('guard_name', 32);
                if ($name === 'roles') {
                    $table->string('label', 100);
                }
                $table->timestamps();
                $table->unique(['name', 'guard_name']);
            });
        }
        foreach (['roles' => 'role_id', 'permissions' => 'permission_id'] as $parent => $key) {
            Schema::create('model_has_'.$parent, function (Blueprint $table) use ($parent, $key): void {
                $table->ulid($key);
                $table->ulid('model_id');
                $table->string('model_type', 64);
                $table->primary([$key, 'model_id', 'model_type']);
                $table->index(['model_id', 'model_type']);
                $table->foreign($key)->references('id')->on($parent)->noActionOnDelete();
                $table->foreign('model_id')->references('id')->on('users')->noActionOnDelete();
            });
            DB::statement("ALTER TABLE model_has_{$parent} ADD CONSTRAINT model_has_{$parent}_user_type CHECK (model_type = 'users.user')");
        }
        Schema::create('role_has_permissions', function (Blueprint $table): void {
            $table->ulid('role_id');
            $table->ulid('permission_id');
            $table->primary(['role_id', 'permission_id']);
            $table->index('permission_id');
            $table->foreign('role_id')->references('id')->on('roles')->noActionOnDelete();
            $table->foreign('permission_id')->references('id')->on('permissions')->noActionOnDelete();
        });
        Schema::create('user_password_histories', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('user_id')->index();
            $table->string('password_hash', 255);
            $table->timestamps();
            $table->foreign('user_id')->references('id')->on('users')->noActionOnDelete();
        });
        Schema::create('user_security_locks', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
        });
        DB::table('user_security_locks')->insert(['id' => 1]);
    }

    public function down(): void
    {
        // No permitir un rollback accidental de identidades operativas.
        $actual = DB::selectOne('SELECT DB_NAME() AS name')->name;
        if (! str_ends_with(strtolower($actual), '_test')) {
            throw new RuntimeException('La reversión destructiva solo está habilitada en bases terminadas en _test.');
        }
        foreach (['user_password_histories', 'role_has_permissions', 'model_has_roles', 'model_has_permissions', 'user_security_locks', 'permissions', 'roles', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
