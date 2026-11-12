<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_security_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('event_type', 64)->index();
            $table->ulid('actor_id')->nullable();
            $table->ulid('subject_id')->nullable()->index();
            $table->ulid('record_id')->nullable();
            $table->string('result', 16);
            $table->string('client', 8);
            $table->string('request_id', 64)->nullable();
            $table->timestamp('occurred_at')->index();
            $table->index(['subject_id', 'occurred_at']);
            $table->foreign('actor_id')->references('id')->on('users')->noActionOnDelete();
            $table->foreign('subject_id')->references('id')->on('users')->noActionOnDelete();
        });
        DB::statement("ALTER TABLE audit_security_events ADD CONSTRAINT audit_security_result_check CHECK (result IN ('success', 'failure', 'blocked'))");
        DB::statement("ALTER TABLE audit_security_events ADD CONSTRAINT audit_security_client_check CHECK (client IN ('web', 'api', 'console'))");
    }

    public function down(): void
    {
        if (! str_ends_with(strtolower(DB::selectOne('SELECT DB_NAME() AS name')->name), '_test')) {
            throw new RuntimeException('Rollback permitido únicamente en bases _test.');
        }
        Schema::dropIfExists('audit_security_events');
    }
};
