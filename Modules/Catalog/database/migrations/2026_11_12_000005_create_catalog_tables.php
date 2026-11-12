<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['catalog_categories', 'catalog_products'] as $table) {
            if (Schema::hasTable($table)) {
                throw new RuntimeException('Ya existe una tabla del catálogo; revisar sin sobrescribir.');
            }
        }
        Schema::create('catalog_categories', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name', 150)->collation('Latin1_General_100_CI_AI_SC')->unique();
            $table->string('description', 1000)->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('display_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['deleted_at', 'is_active', 'display_order']);
        });
        Schema::create('catalog_products', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('category_id')->constrained('catalog_categories')->noActionOnDelete();
            $table->string('name', 150)->collation('Latin1_General_100_CI_AI_SC')->index();
            $table->string('description', 2000)->nullable();
            $table->decimal('price', 19, 4);
            $table->boolean('is_active')->default(true);
            $table->string('image_disk', 32)->nullable();
            $table->string('image_key', 128)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['category_id', 'deleted_at', 'is_active']);
            $table->index(['deleted_at', 'is_active', 'price']);
        });
        foreach (['catalog_categories', 'catalog_products'] as $table) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_name_check CHECK (LEN(name) > 0 AND DATALENGTH(name) = DATALENGTH(LTRIM(RTRIM(name))) AND CHARINDEX(N'  ', name) = 0 AND CHARINDEX(NCHAR(9), name) = 0 AND CHARINDEX(NCHAR(10), name) = 0 AND CHARINDEX(NCHAR(13), name) = 0)");
        }
        DB::statement('ALTER TABLE catalog_categories ADD CONSTRAINT catalog_categories_order_check CHECK (display_order >= 0)');
        DB::statement('ALTER TABLE catalog_products ADD CONSTRAINT catalog_products_price_check CHECK (price >= 0)');
        DB::statement("ALTER TABLE catalog_products ADD CONSTRAINT catalog_products_image_check CHECK ((image_disk IS NULL AND image_key IS NULL) OR (image_disk IS NOT NULL AND image_key IS NOT NULL AND image_key LIKE 'catalog/products/%.webp' AND image_key NOT LIKE '%..%' AND CHARINDEX(':', image_key) = 0 AND CHARINDEX(NCHAR(92), image_key) = 0))");
    }

    public function down(): void
    {
        if (! str_ends_with(strtolower(DB::selectOne('SELECT DB_NAME() AS name')->name), '_test')) {
            throw new RuntimeException('Rollback permitido únicamente en bases _test.');
        }
        Schema::dropIfExists('catalog_products');
        Schema::dropIfExists('catalog_categories');
    }
};
