<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['sales_tables', 'sales_sales', 'sales_items'] as $name) {
            if (Schema::hasTable($name)) {
                throw new RuntimeException('Ya existe una tabla de Sales; revisar sin sobrescribir.');
            }
        }
        Schema::create('sales_tables', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name', 150)->collation('Latin1_General_100_CI_AI_SC')->unique();
            $table->boolean('is_active')->default(true);
            $table->integer('display_order')->default(0);
            $table->timestamps();
            $table->index(['is_active', 'name']);
        });
        Schema::create('sales_sales', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('type', 16);
            $table->string('status', 16);
            $table->foreignUlid('table_id')->nullable()->constrained('sales_tables')->noActionOnDelete();
            $table->foreignUlid('created_by')->constrained('users')->noActionOnDelete();
            // Cash no tiene tablas todavía: referencia contractual, sin inventar una FK destino.
            $table->ulid('cash_session_id');
            $table->string('note', 150)->nullable();
            $table->decimal('subtotal', 19, 4)->default('0');
            $table->decimal('discount_amount', 19, 4)->default('0');
            $table->decimal('total', 19, 4)->default('0');
            $table->string('folio', 12)->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignUlid('cancelled_by')->nullable()->constrained('users')->noActionOnDelete();
            $table->string('cancellation_reason', 150)->nullable();
            $table->timestamps();
            $table->index(['status', 'type', 'created_at', 'id']);
            $table->index(['created_by', 'status', 'created_at']);
            $table->index(['table_id', 'status']);
            $table->index('cash_session_id');
        });
        Schema::create('sales_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('sale_id')->constrained('sales_sales')->noActionOnDelete();
            $table->foreignUlid('product_id')->constrained('catalog_products')->noActionOnDelete();
            $table->string('product_name', 150);
            $table->foreignUlid('category_id')->constrained('catalog_categories')->noActionOnDelete();
            $table->string('category_name', 150);
            $table->decimal('unit_price', 19, 4);
            $table->integer('quantity');
            $table->string('note', 100)->nullable();
            $table->decimal('line_total', 19, 4);
            $table->integer('position');
            $table->timestamp('removed_at')->nullable();
            $table->foreignUlid('removed_by')->nullable()->constrained('users')->noActionOnDelete();
            // Código validado por un futuro catálogo autorizado; sin texto libre ni catálogo ficticio.
            $table->string('removal_reason_code', 64)->nullable();
            $table->timestamps();
            $table->unique(['sale_id', 'position']);
            $table->index(['sale_id', 'removed_at', 'position']);
            $table->index('product_id');
            $table->index('category_id');
        });
        DB::statement("ALTER TABLE sales_tables ADD CONSTRAINT sales_tables_values_check CHECK (LEN(name)>0 AND DATALENGTH(name)=DATALENGTH(LTRIM(RTRIM(name))) AND CHARINDEX(N'  ',name)=0 AND display_order>=0)");
        DB::statement("ALTER TABLE sales_sales ADD CONSTRAINT sales_type_table_check CHECK ((type='counter' AND table_id IS NULL) OR (type='table' AND table_id IS NOT NULL))");
        DB::statement("ALTER TABLE sales_sales ADD CONSTRAINT sales_state_check CHECK (status IN ('open','paused','closed','cancelled','reopened') AND (status<>'paused' OR type='counter'))");
        DB::statement('ALTER TABLE sales_sales ADD CONSTRAINT sales_amounts_check CHECK (subtotal>=0 AND discount_amount>=0 AND discount_amount<=subtotal AND total=subtotal-discount_amount)');
        DB::statement("ALTER TABLE sales_sales ADD CONSTRAINT sales_closed_check CHECK ((status IN ('closed','reopened') AND closed_at IS NOT NULL AND folio IS NOT NULL) OR (status IN ('open','paused','cancelled') AND closed_at IS NULL AND folio IS NULL))");
        DB::statement("ALTER TABLE sales_sales ADD CONSTRAINT sales_folio_check CHECK (folio IS NULL OR (LEN(folio)=12 AND folio NOT LIKE '%[^0-9]%' AND TRY_CONVERT(date,LEFT(folio,8),112) IS NOT NULL AND RIGHT(folio,4)<>'0000'))");
        DB::statement("ALTER TABLE sales_sales ADD CONSTRAINT sales_cancelled_check CHECK ((status='cancelled' AND cancelled_at IS NOT NULL AND cancelled_by IS NOT NULL AND cancellation_reason IS NOT NULL AND LEN(cancellation_reason)>0) OR (status<>'cancelled' AND cancelled_at IS NULL AND cancelled_by IS NULL AND cancellation_reason IS NULL))");
        DB::statement("CREATE UNIQUE INDEX sales_one_active_table ON sales_sales(table_id) WHERE table_id IS NOT NULL AND status IN ('open','reopened')");
        DB::statement('CREATE UNIQUE INDEX sales_unique_folio ON sales_sales(folio) WHERE folio IS NOT NULL');
        DB::statement('ALTER TABLE sales_items ADD CONSTRAINT sales_items_amounts_check CHECK (unit_price>=0 AND quantity>0 AND line_total>=0 AND line_total=unit_price*CAST(quantity AS decimal(10,0)) AND position>0)');
        DB::statement('ALTER TABLE sales_items ADD CONSTRAINT sales_items_removed_check CHECK ((removed_at IS NULL AND removed_by IS NULL AND removal_reason_code IS NULL) OR (removed_at IS NOT NULL AND removed_by IS NOT NULL AND removal_reason_code IS NOT NULL AND LEN(removal_reason_code)>0))');
    }

    public function down(): void
    {
        if (! str_ends_with(strtolower(DB::selectOne('SELECT DB_NAME() AS name')->name), '_test')) {
            throw new RuntimeException('Rollback permitido únicamente en bases _test.');
        }
        Schema::dropIfExists('sales_items');
        Schema::dropIfExists('sales_sales');
        Schema::dropIfExists('sales_tables');
    }
};
