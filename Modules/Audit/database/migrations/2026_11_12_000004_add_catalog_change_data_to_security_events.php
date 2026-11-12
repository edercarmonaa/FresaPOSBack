<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('audit_security_events', 'change_data')) {
            throw new RuntimeException('La extensión de auditoría ya existe; revisar sin sobrescribir.');
        }
        Schema::table('audit_security_events', fn (Blueprint $table) => $table->string('change_data', 1000)->nullable());
        DB::statement('ALTER TABLE audit_security_events ADD CONSTRAINT audit_security_change_data_check CHECK (change_data IS NULL OR ISJSON(change_data) = 1)');
    }

    public function down(): void
    {
        if (! str_ends_with(strtolower(DB::selectOne('SELECT DB_NAME() AS name')->name), '_test')) {
            throw new RuntimeException('Rollback permitido únicamente en bases _test.');
        }
        DB::statement('ALTER TABLE audit_security_events DROP CONSTRAINT audit_security_change_data_check');
        Schema::table('audit_security_events', fn (Blueprint $table) => $table->dropColumn('change_data'));
    }
};
