<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales_operation_reasons')) {
            throw new RuntimeException('El catálogo ya existe; revisar sin sobrescribir.');
        }
        Schema::create('sales_operation_reasons', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('code', 64)->unique();
            $table->string('name', 150)->collation('Latin1_General_100_CI_AI_SC');
            $table->string('description', 500)->nullable();
            $table->string('operation', 32);
            $table->boolean('is_active')->default(true);
            $table->integer('display_order')->default(0);
            $table->timestamps();
            $table->unique(['operation', 'name']);
            $table->index(['operation', 'is_active', 'display_order', 'id']);
        });
        DB::statement("ALTER TABLE sales_operation_reasons ADD CONSTRAINT sales_reason_values CHECK (operation COLLATE Latin1_General_100_BIN2 IN ('sale_item_removal','sale_cancellation') AND display_order>=0 AND LEN(LTRIM(RTRIM(name)))>0 AND name=LTRIM(RTRIM(name)) AND code COLLATE Latin1_General_100_BIN2 NOT LIKE '%[^A-Z0-9_:-]%' AND LEN(code)>0 AND LEFT(code,1) COLLATE Latin1_General_100_BIN2 LIKE '[A-Z0-9]')");
        Schema::table('sales_items', function (Blueprint $table): void {
            $table->foreignUlid('removal_reason_id')->nullable()->constrained('sales_operation_reasons')->noActionOnDelete();
            $table->text('removal_reason_snapshot')->nullable();
        });
        Schema::table('sales_sales', function (Blueprint $table): void {
            $table->foreignUlid('cancellation_reason_id')->nullable()->constrained('sales_operation_reasons')->noActionOnDelete();
            $table->text('cancellation_reason_snapshot')->nullable();
        });
        DB::statement('ALTER TABLE sales_items ADD CONSTRAINT sales_removal_reason_json CHECK ((removal_reason_id IS NULL AND removal_reason_snapshot IS NULL) OR (removal_reason_id IS NOT NULL AND removal_reason_snapshot IS NOT NULL AND ISJSON(removal_reason_snapshot)=1))');
        DB::statement('ALTER TABLE sales_sales ADD CONSTRAINT sales_cancellation_reason_json CHECK ((cancellation_reason_id IS NULL AND cancellation_reason_snapshot IS NULL) OR (cancellation_reason_id IS NOT NULL AND cancellation_reason_snapshot IS NOT NULL AND ISJSON(cancellation_reason_snapshot)=1))');
        DB::unprepared("CREATE TRIGGER sales_reason_identity_immutable ON sales_operation_reasons AFTER UPDATE, DELETE AS BEGIN SET NOCOUNT ON;
            IF EXISTS(SELECT 1 FROM deleted d LEFT JOIN inserted i ON i.id=d.id WHERE i.id IS NULL OR i.code COLLATE Latin1_General_100_BIN2<>d.code COLLATE Latin1_General_100_BIN2 OR i.operation COLLATE Latin1_General_100_BIN2<>d.operation COLLATE Latin1_General_100_BIN2) THROW 51040, 'Reason identity is immutable; physical deletion is forbidden.', 1; END");
        DB::unprepared("CREATE TRIGGER sales_removed_item_immutable ON sales_items AFTER INSERT, UPDATE, DELETE AS BEGIN SET NOCOUNT ON;
            IF EXISTS(SELECT 1 FROM deleted WHERE removed_at IS NOT NULL)
                OR EXISTS(SELECT 1 FROM deleted d JOIN sales_sales s ON s.id=d.sale_id WHERE s.status='cancelled')
                OR EXISTS(SELECT 1 FROM inserted i JOIN sales_sales s ON s.id=i.sale_id WHERE s.status='cancelled')
                THROW 51041, 'Removed or cancelled line history is immutable.', 1; END");
        DB::unprepared("CREATE TRIGGER sales_cancelled_immutable ON sales_sales AFTER UPDATE, DELETE AS BEGIN SET NOCOUNT ON;
            IF EXISTS(SELECT 1 FROM deleted WHERE status='cancelled') THROW 51042, 'Cancelled sale history is immutable.', 1; END");
    }

    public function down(): void
    {
        if (! str_ends_with(strtolower(DB::selectOne('SELECT DB_NAME() AS name')->name), '_test')) {
            throw new RuntimeException('Rollback solo en bases _test.');
        }
        DB::unprepared('DROP TRIGGER IF EXISTS sales_reason_identity_immutable; DROP TRIGGER IF EXISTS sales_removed_item_immutable; DROP TRIGGER IF EXISTS sales_cancelled_immutable;');
        DB::statement('ALTER TABLE sales_items DROP CONSTRAINT sales_removal_reason_json');
        DB::statement('ALTER TABLE sales_sales DROP CONSTRAINT sales_cancellation_reason_json');
        Schema::table('sales_items', function (Blueprint $table): void {
            $table->dropForeign(['removal_reason_id']);
            $table->dropColumn(['removal_reason_id', 'removal_reason_snapshot']);
        });
        Schema::table('sales_sales', function (Blueprint $table): void {
            $table->dropForeign(['cancellation_reason_id']);
            $table->dropColumn(['cancellation_reason_id', 'cancellation_reason_snapshot']);
        });
        Schema::drop('sales_operation_reasons');
    }
};
