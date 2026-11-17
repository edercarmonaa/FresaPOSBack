<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cash_payment_methods') || Schema::hasTable('cash_movements')) {
            throw new RuntimeException('Ya existe persistencia financiera; revisar sin sobrescribir.');
        }
        Schema::create('cash_payment_methods', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('code', 16)->unique();
            $table->string('name', 80);
            $table->boolean('is_active')->default(true);
            $table->integer('display_order');
            $table->timestamps();
            $table->unique(['id', 'code'], 'cash_method_identity_unique');
        });
        DB::statement("ALTER TABLE cash_payment_methods ADD CONSTRAINT cash_method_values CHECK (code IN ('cash','card','transfer','other') AND LEN(name)>0 AND display_order>=0)");
        Schema::create('cash_movements', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('cash_session_id')->constrained('cash_sessions')->noActionOnDelete();
            $table->string('type', 16);
            $table->decimal('amount', 19, 4);
            $table->string('concept', 200);
            $table->foreignUlid('created_by')->constrained('users')->noActionOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at');
            $table->index(['cash_session_id', 'occurred_at', 'id'], 'cash_movements_session_date');
            $table->index(['cash_session_id', 'type', 'occurred_at'], 'cash_movements_session_type');
            $table->index(['created_by', 'occurred_at']);
        });
        DB::statement("ALTER TABLE cash_movements ADD CONSTRAINT cash_movement_values CHECK (type IN ('cash_in','cash_out') AND amount>0 AND LEN(LTRIM(RTRIM(concept)))>0)");
    }

    public function down(): void
    {
        if (! str_ends_with(strtolower(DB::selectOne('SELECT DB_NAME() AS name')->name), '_test')) {
            throw new RuntimeException('Rollback permitido únicamente en bases _test.');
        }
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('cash_payment_methods');
    }
};
