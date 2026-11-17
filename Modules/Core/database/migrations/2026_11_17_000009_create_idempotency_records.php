<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('idempotency_records')) {
            throw new RuntimeException('La persistencia de operaciones ya existe; revisar sin sobrescribir.');
        }
        Schema::create('idempotency_records', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            // Contexto genérico opaco, no una referencia a modelos funcionales de Users.
            $table->char('owner_context', 64);
            $table->uuid('idempotency_key');
            $table->string('operation', 96);
            $table->string('resource_id', 128)->nullable();
            $table->char('request_hash', 64);
            $table->string('state', 16);
            $table->smallInteger('http_status')->nullable();
            $table->text('response')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unique(['owner_context', 'idempotency_key'], 'idempotency_owner_key_unique');
            $table->index(['state', 'expires_at'], 'idempotency_cleanup_index');
        });
        DB::statement("ALTER TABLE idempotency_records ADD CONSTRAINT idempotency_state_check CHECK (state IN ('pending','completed','failed') AND ((state='pending' AND http_status IS NULL AND response IS NULL AND completed_at IS NULL AND expires_at IS NULL) OR (state='completed' AND http_status BETWEEN 200 AND 299 AND completed_at IS NOT NULL AND expires_at>=completed_at) OR (state='failed' AND http_status BETWEEN 400 AND 499 AND completed_at IS NOT NULL AND expires_at>=completed_at)))");
        DB::statement('ALTER TABLE idempotency_records ADD CONSTRAINT idempotency_json_check CHECK (response IS NULL OR ISJSON(response)=1)');
        DB::statement("ALTER TABLE idempotency_records ADD CONSTRAINT idempotency_uuid_v4_check CHECK (SUBSTRING(CONVERT(varchar(36),idempotency_key),15,1)='4' AND SUBSTRING(CONVERT(varchar(36),idempotency_key),20,1) IN ('8','9','a','b'))");
    }

    public function down(): void
    {
        if (! str_ends_with(strtolower(DB::selectOne('SELECT DB_NAME() AS name')->name), '_test')) {
            throw new RuntimeException('Rollback permitido únicamente en bases _test.');
        }
        Schema::dropIfExists('idempotency_records');
    }
};
