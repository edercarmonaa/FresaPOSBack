<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('sales_sales', 'discount_type') || DB::table('sales_sales')->where('discount_amount', '<>', 0)->exists()) {
            throw new RuntimeException('Revisar evidencia de descuentos preexistente sin sobrescribir ni inventar autorizaciones.');
        }
        Schema::table('sales_sales', function (Blueprint $table): void {
            $table->string('discount_type', 16)->nullable();
            $table->decimal('discount_value', 19, 4)->nullable();
            $table->foreignUlid('discount_authorized_by')->nullable()->constrained('users')->noActionOnDelete();
            $table->dateTimeTz('discount_authorized_at')->nullable();
        });
        DB::statement("ALTER TABLE sales_sales ADD CONSTRAINT sales_authorized_discount CHECK (
            (discount_type IS NULL AND discount_value IS NULL AND discount_authorized_by IS NULL AND discount_authorized_at IS NULL AND discount_amount=0)
            OR (discount_type IS NOT NULL AND discount_value IS NOT NULL AND discount_authorized_by IS NOT NULL AND discount_authorized_at IS NOT NULL AND discount_value>0
                AND ((discount_type COLLATE Latin1_General_100_BIN2='fixed' AND discount_value<=subtotal AND discount_amount=discount_value)
                    OR (discount_type COLLATE Latin1_General_100_BIN2='percent' AND discount_value BETWEEN 1 AND 100 AND discount_value=FLOOR(discount_value)
                        AND discount_amount=ROUND(CAST(subtotal AS decimal(21,4))*TRY_CONVERT(decimal(7,4),discount_value)/100,4)))))");
    }

    public function down(): void
    {
        if (! str_ends_with(strtolower(DB::selectOne('SELECT DB_NAME() AS name')->name), '_test')) {
            throw new RuntimeException('Rollback solamente en bases _test.');
        }
        DB::statement('ALTER TABLE sales_sales DROP CONSTRAINT sales_authorized_discount');
        Schema::table('sales_sales', function (Blueprint $table): void {
            $table->dropForeign(['discount_authorized_by']);
            $table->dropColumn(['discount_type', 'discount_value', 'discount_authorized_by', 'discount_authorized_at']);
        });
    }
};
