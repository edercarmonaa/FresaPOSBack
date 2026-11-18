<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_reconciliations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('cash_session_id')->unique()->constrained('cash_sessions')->noActionOnDelete();
            $table->foreignUlid('counted_by')->constrained('users')->noActionOnDelete();
            $table->decimal('counted_cash', 19, 4);
            $table->decimal('expected_cash', 19, 4);
            $table->decimal('difference', 19, 4);
            $table->string('observation', 200)->nullable();
            $table->timestamp('confirmed_at');
            $table->text('summary');
            $table->timestamps();
            $table->index(['confirmed_at', 'id']);
            $table->index(['counted_by', 'confirmed_at']);
        });
        DB::statement('ALTER TABLE cash_reconciliations ADD CONSTRAINT cash_reconciliation_values CHECK (counted_cash>=0 AND expected_cash>=0 AND difference=counted_cash-expected_cash AND ISJSON(summary)=1 AND (difference=0 OR LEN(LTRIM(RTRIM(observation)))>0 AND observation IS NOT NULL))');
        DB::unprepared("CREATE TRIGGER cash_reconciliation_immutable ON cash_reconciliations AFTER UPDATE, DELETE AS BEGIN SET NOCOUNT ON; IF EXISTS (SELECT 1 FROM deleted) THROW 51030, 'Confirmed reconciliation is immutable.', 1; END");
        DB::unprepared("CREATE TRIGGER cash_closed_session_immutable ON cash_sessions AFTER UPDATE, DELETE AS BEGIN SET NOCOUNT ON;
            IF EXISTS (SELECT 1 FROM deleted WHERE status='closed') THROW 51031, 'Closed cash session is immutable.', 1;
            IF EXISTS (SELECT 1 FROM inserted i WHERE i.status='closed' AND NOT EXISTS (SELECT 1 FROM cash_reconciliations r WHERE r.cash_session_id=i.id AND r.counted_by=i.closed_by AND r.confirmed_at=i.closed_at)) THROW 51032, 'A matching reconciliation is required.', 1;
            END");
        DB::unprepared("CREATE TRIGGER cash_movement_session_guard ON cash_movements AFTER INSERT, UPDATE, DELETE AS BEGIN SET NOCOUNT ON;
            IF EXISTS (SELECT 1 FROM deleted) THROW 51033, 'Cash movements are immutable.', 1;
            IF EXISTS (SELECT 1 FROM inserted i JOIN cash_sessions s WITH (UPDLOCK,HOLDLOCK) ON s.id=i.cash_session_id WHERE s.status<>'open') THROW 51034, 'Cash session is closed.', 1;
            END");
    }

    public function down(): void
    {
        if (! str_ends_with(strtolower(DB::selectOne('SELECT DB_NAME() AS name')->name), '_test')) {
            throw new RuntimeException('Rollback permitido únicamente en bases _test.');
        }
        DB::unprepared('DROP TRIGGER IF EXISTS cash_movement_session_guard');
        DB::unprepared('DROP TRIGGER IF EXISTS cash_closed_session_immutable');
        DB::unprepared('DROP TRIGGER IF EXISTS cash_reconciliation_immutable');
        Schema::dropIfExists('cash_reconciliations');
    }
};
