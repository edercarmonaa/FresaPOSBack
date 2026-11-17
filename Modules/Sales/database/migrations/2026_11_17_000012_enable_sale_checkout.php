<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales_daily_sequences') || Schema::hasColumn('sales_sales', 'closed_by')) {
            throw new RuntimeException('Ya existe estructura de checkout; revisar sin sobrescribir.');
        }
        Schema::create('sales_daily_sequences', function (Blueprint $table): void {
            $table->date('business_date')->primary();
            $table->integer('last_value');
        });
        DB::statement('ALTER TABLE sales_daily_sequences ADD CONSTRAINT sales_daily_sequence_range CHECK (last_value BETWEEN 1 AND 9999)');
        Schema::table('sales_sales', function (Blueprint $table): void {
            $table->foreignUlid('closed_by')->nullable()->constrained('users')->noActionOnDelete();
            $table->unique(['id', 'cash_session_id'], 'sales_session_identity_unique');
        });
        Schema::table('sales_payments', function (Blueprint $table): void {
            $table->foreign(['sale_id', 'cash_session_id'], 'sales_payment_original_session')->references(['id', 'cash_session_id'])->on('sales_sales')->noActionOnDelete();
        });
        DB::statement('ALTER TABLE sales_payments DROP CONSTRAINT sales_payment_prepared_only');
        DB::statement("ALTER TABLE sales_payments ADD CONSTRAINT sales_payment_financial_values CHECK (((status='prepared' AND confirmed_at IS NULL) OR (status='confirmed' AND confirmed_at IS NOT NULL)) AND applied_amount>0 AND ((method_code='cash' AND received_cash IS NOT NULL AND change_cash IS NOT NULL AND received_cash>=applied_amount AND change_cash=received_cash-applied_amount) OR (method_code IN ('card','transfer','other') AND received_cash IS NULL AND change_cash IS NULL)) AND (method_code NOT IN ('transfer','other') OR (reference IS NOT NULL AND LEN(LTRIM(RTRIM(reference)))>0)))");
        // No reescribe datos históricos; las tres barreras protegen escrituras futuras.
        DB::unprepared("CREATE TRIGGER sales_closed_immutable ON sales_sales AFTER UPDATE, DELETE AS BEGIN SET NOCOUNT ON; IF EXISTS(SELECT 1 FROM deleted WHERE folio IS NOT NULL) THROW 51001, 'El cierre financiero es inmutable.', 1; END");
        DB::unprepared("CREATE TRIGGER sales_paid_items_immutable ON sales_items AFTER INSERT, UPDATE, DELETE AS BEGIN SET NOCOUNT ON; IF EXISTS(SELECT 1 FROM sales_sales s JOIN (SELECT sale_id FROM inserted UNION SELECT sale_id FROM deleted) x ON x.sale_id=s.id WHERE s.folio IS NOT NULL) THROW 51002, 'Las líneas financieras son inmutables.', 1; END");
        DB::unprepared("CREATE TRIGGER sales_confirmed_payments_immutable ON sales_payments AFTER UPDATE, DELETE AS BEGIN SET NOCOUNT ON; IF EXISTS(SELECT 1 FROM deleted WHERE status='confirmed') THROW 51003, 'Los pagos confirmados son inmutables.', 1; END");
    }

    public function down(): void
    {
        if (! str_ends_with(strtolower(DB::selectOne('SELECT DB_NAME() AS name')->name), '_test')) {
            throw new RuntimeException('Rollback permitido únicamente en bases _test.');
        }
        DB::unprepared('DROP TRIGGER IF EXISTS sales_closed_immutable; DROP TRIGGER IF EXISTS sales_paid_items_immutable; DROP TRIGGER IF EXISTS sales_confirmed_payments_immutable;');
        // Solo limpieza de fixtures: 000011 se elimina inmediatamente después.
        Schema::table('sales_payments', fn (Blueprint $table) => $table->dropForeign('sales_payment_original_session'));
        Schema::table('sales_sales', function (Blueprint $table): void {
            $table->dropForeign(['closed_by']);
            $table->dropUnique('sales_session_identity_unique');
            $table->dropColumn('closed_by');
        });
        Schema::drop('sales_daily_sequences');
    }
};
