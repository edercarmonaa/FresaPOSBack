<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared("CREATE TRIGGER sales_cash_session_guard ON sales_sales AFTER INSERT, UPDATE AS BEGIN SET NOCOUNT ON;
            IF EXISTS (SELECT 1 FROM inserted i JOIN cash_sessions s WITH (UPDLOCK,HOLDLOCK) ON s.id=i.cash_session_id WHERE s.status<>'open') THROW 51035, 'Cash session is closed.', 1;
            END");
        DB::unprepared("CREATE TRIGGER sales_item_cash_session_guard ON sales_items AFTER INSERT, UPDATE, DELETE AS BEGIN SET NOCOUNT ON;
            IF EXISTS (SELECT 1 FROM (SELECT sale_id FROM inserted UNION SELECT sale_id FROM deleted) i JOIN sales_sales sale ON sale.id=i.sale_id JOIN cash_sessions s WITH (UPDLOCK,HOLDLOCK) ON s.id=sale.cash_session_id WHERE s.status<>'open') THROW 51036, 'Cash session is closed.', 1;
            END");
        DB::unprepared("CREATE TRIGGER sales_payment_cash_session_guard ON sales_payments AFTER INSERT, UPDATE AS BEGIN SET NOCOUNT ON;
            IF EXISTS (SELECT 1 FROM inserted i JOIN cash_sessions s WITH (UPDLOCK,HOLDLOCK) ON s.id=i.cash_session_id WHERE s.status<>'open') THROW 51037, 'Cash session is closed.', 1;
            END");
    }

    public function down(): void
    {
        if (! str_ends_with(strtolower(DB::selectOne('SELECT DB_NAME() AS name')->name), '_test')) {
            throw new RuntimeException('Rollback permitido únicamente en bases _test.');
        }
        foreach (['sales_payment_cash_session_guard', 'sales_item_cash_session_guard', 'sales_cash_session_guard'] as $trigger) {
            DB::unprepared('DROP TRIGGER IF EXISTS '.$trigger);
        }
    }
};
