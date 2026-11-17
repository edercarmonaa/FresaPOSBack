<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('sales_sales')->whereNotExists(fn ($query) => $query->selectRaw('1')->from('cash_sessions')->whereColumn('cash_sessions.id', 'sales_sales.cash_session_id'))->exists()) {
            throw new RuntimeException('Hay ventas con sesiones sin referencia Cash. No se modificó su historia; resolver antes de migrar.');
        }
        Schema::table('sales_sales', fn (Blueprint $table) => $table->foreign('cash_session_id', 'sales_cash_session_foreign')->references('id')->on('cash_sessions')->noActionOnDelete());
    }

    public function down(): void
    {
        if (! str_ends_with(strtolower(DB::selectOne('SELECT DB_NAME() AS name')->name), '_test')) {
            throw new RuntimeException('Rollback permitido únicamente en bases _test.');
        }
        Schema::table('sales_sales', fn (Blueprint $table) => $table->dropForeign('sales_cash_session_foreign'));
    }
};
