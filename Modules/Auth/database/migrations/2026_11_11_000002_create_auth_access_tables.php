<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['personal_access_tokens', 'auth_access_sessions', 'auth_employee_pins'] as $name) {
            if (Schema::hasTable($name)) {
                throw new RuntimeException('Existe una tabla de autenticación; revisar integración sin sobrescribir.');
            }
        }
        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('tokenable_id')->index();
            $table->string('tokenable_type', 64);
            $table->string('name', 64);
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
            $table->foreign('tokenable_id')->references('id')->on('users')->noActionOnDelete();
        });
        DB::statement("ALTER TABLE personal_access_tokens ADD CONSTRAINT personal_access_tokens_user_type CHECK (tokenable_type = 'users.user')");
        Schema::create('auth_access_sessions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('user_id')->index();
            $table->string('channel', 8);
            $table->string('auth_method', 12)->default('password');
            $table->string('session_hash', 64)->nullable();
            $table->ulid('token_id')->nullable()->index();
            $table->ulid('terminal_owner_id')->nullable();
            $table->timestamp('last_activity_at');
            $table->timestamp('expires_at')->index();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->foreign('user_id')->references('id')->on('users')->noActionOnDelete();
            $table->foreign('terminal_owner_id')->references('id')->on('users')->noActionOnDelete();
            $table->foreign('token_id')->references('id')->on('personal_access_tokens')->nullOnDelete();
        });
        // SQL Server requiere índice filtrado para permitir múltiples NULL.
        DB::statement('CREATE UNIQUE INDEX auth_sessions_web_hash_unique ON auth_access_sessions(session_hash) WHERE session_hash IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX auth_sessions_token_unique ON auth_access_sessions(token_id) WHERE token_id IS NOT NULL');
        DB::statement("ALTER TABLE auth_access_sessions ADD CONSTRAINT auth_sessions_channel_check CHECK ((channel = 'web' AND session_hash IS NOT NULL AND token_id IS NULL) OR (channel = 'api' AND session_hash IS NULL))");
        Schema::create('auth_employee_pins', function (Blueprint $table): void {
            $table->ulid('user_id')->primary();
            $table->string('pin_hash', 255);
            $table->timestamps();
            $table->foreign('user_id')->references('id')->on('users')->noActionOnDelete();
        });
    }

    public function down(): void
    {
        if (! str_ends_with(strtolower(DB::selectOne('SELECT DB_NAME() AS name')->name), '_test')) {
            throw new RuntimeException('Rollback destructivo permitido únicamente en bases _test.');
        }
        foreach (['auth_employee_pins', 'auth_access_sessions', 'personal_access_tokens'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
