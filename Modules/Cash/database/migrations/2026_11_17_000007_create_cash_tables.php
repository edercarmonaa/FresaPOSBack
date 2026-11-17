<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cash_registers') || Schema::hasTable('cash_sessions')) {
            throw new RuntimeException('Ya existe infraestructura Cash; revisar antes de continuar.');
        }
        Schema::create('cash_registers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->unsignedTinyInteger('slot')->default(1)->unique();
            $table->string('name', 150)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('cash_sessions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('register_id')->constrained('cash_registers')->noActionOnDelete();
            $table->foreignUlid('opened_by')->constrained('users')->noActionOnDelete();
            $table->string('status', 16);
            $table->decimal('opening_fund', 19, 4);
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->foreignUlid('closed_by')->nullable()->constrained('users')->noActionOnDelete();
            $table->char('opening_key_hash', 64);
            $table->char('opening_fingerprint', 64);
            $table->timestamps();
            $table->unique(['opened_by', 'opening_key_hash']);
            $table->index(['register_id', 'opened_at']);
            $table->index(['status', 'opened_at']);
        });
        DB::statement('ALTER TABLE cash_registers ADD CONSTRAINT cash_single_register CHECK (slot=1 AND LEN(name)>0)');
        DB::statement("ALTER TABLE cash_sessions ADD CONSTRAINT cash_session_values CHECK (opening_fund>=0 AND status IN ('open','closed') AND ((status='open' AND closed_at IS NULL AND closed_by IS NULL) OR (status='closed' AND closed_at IS NOT NULL AND closed_by IS NOT NULL AND closed_at>=opened_at)))");
        DB::statement("CREATE UNIQUE INDEX cash_one_open_session ON cash_sessions(register_id) WHERE status='open'");
    }

    public function down(): void
    {
        if (! str_ends_with(strtolower(DB::selectOne('SELECT DB_NAME() AS name')->name), '_test')) {
            throw new RuntimeException('Rollback permitido únicamente en bases _test.');
        }
        Schema::dropIfExists('cash_sessions');
        Schema::dropIfExists('cash_registers');
    }
};
