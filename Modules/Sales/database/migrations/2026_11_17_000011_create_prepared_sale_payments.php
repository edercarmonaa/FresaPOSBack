<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales_payments')) {
            throw new RuntimeException('Ya existe persistencia de pagos; revisar sin sobrescribir.');
        }
        Schema::create('sales_payments', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('sale_id')->constrained('sales_sales')->noActionOnDelete();
            $table->foreignUlid('cash_session_id')->constrained('cash_sessions')->noActionOnDelete();
            $table->ulid('payment_method_id');
            $table->string('method_code', 16);
            $table->decimal('applied_amount', 19, 4);
            $table->decimal('received_cash', 19, 4)->nullable();
            $table->decimal('change_cash', 19, 4)->nullable();
            $table->string('reference', 64)->nullable();
            $table->foreignUlid('created_by')->constrained('users')->noActionOnDelete();
            $table->string('status', 16)->default('prepared');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
            $table->foreign(['payment_method_id', 'method_code'], 'sales_payment_method_foreign')->references(['id', 'code'])->on('cash_payment_methods')->noActionOnDelete();
            $table->index(['sale_id', 'status']);
            $table->index(['cash_session_id', 'created_at']);
        });
        // Barrera deliberada: ninguna fila confirmada es admisible en esta etapa.
        DB::statement("ALTER TABLE sales_payments ADD CONSTRAINT sales_payment_prepared_only CHECK (status='prepared' AND confirmed_at IS NULL AND applied_amount>0 AND ((method_code='cash' AND received_cash IS NOT NULL AND change_cash IS NOT NULL AND received_cash>=applied_amount AND change_cash=received_cash-applied_amount) OR (method_code IN ('card','transfer','other') AND received_cash IS NULL AND change_cash IS NULL)) AND (method_code NOT IN ('transfer','other') OR (reference IS NOT NULL AND LEN(LTRIM(RTRIM(reference)))>0)))");
    }

    public function down(): void
    {
        if (! str_ends_with(strtolower(DB::selectOne('SELECT DB_NAME() AS name')->name), '_test')) {
            throw new RuntimeException('Rollback permitido únicamente en bases _test.');
        }
        Schema::dropIfExists('sales_payments');
    }
};
