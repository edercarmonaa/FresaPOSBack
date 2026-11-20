<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales_corrections')) {
            throw new RuntimeException('Ya existe estructura de correcciones; revisar sin sobrescribir.');
        }
        Schema::create('sales_corrections', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->ulid('sale_id');
            $table->ulid('cash_session_id');
            $table->foreign(['sale_id', 'cash_session_id'], 'sales_correction_original_session')->references(['id', 'cash_session_id'])->on('sales_sales')->noActionOnDelete();
            $table->foreign('cash_session_id')->references('id')->on('cash_sessions')->noActionOnDelete();
            $table->foreignUlid('reopened_by')->constrained('users')->noActionOnDelete();
            $table->timestamp('reopened_at');
            $table->string('observation', 150)->nullable();
            $table->string('status', 16)->default('active');
            $table->text('original_snapshot');
            $table->text('draft_snapshot');
            $table->text('result_snapshot')->nullable();
            $table->integer('version')->default(1);
            $table->decimal('additional_paid', 19, 4)->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->foreignUlid('confirmed_by')->nullable()->constrained('users')->noActionOnDelete();
            // No es una credencial persistente: solo habilita la transacción SQL actual mientras aplica.
            $table->bigInteger('applying_transaction_id')->nullable();
            $table->index(['cash_session_id', 'completed_at', 'sale_id']);
            $table->index(['sale_id', 'reopened_at', 'id']);
        });
        DB::statement('CREATE UNIQUE INDEX sales_one_active_correction ON sales_corrections(sale_id) WHERE completed_at IS NULL');
        DB::statement("ALTER TABLE sales_corrections ADD CONSTRAINT sales_correction_values CHECK (version>0 AND additional_paid>=0 AND ISJSON(original_snapshot)=1 AND ISJSON(draft_snapshot)=1 AND (result_snapshot IS NULL OR ISJSON(result_snapshot)=1) AND ((status='active' AND completed_at IS NULL AND confirmed_by IS NULL AND result_snapshot IS NULL AND additional_paid=0) OR (status IN ('confirmed','unchanged','discarded') AND completed_at IS NOT NULL AND completed_at>=reopened_at AND confirmed_by IS NOT NULL AND result_snapshot IS NOT NULL AND applying_transaction_id IS NULL)))");
        DB::unprepared("CREATE TRIGGER sales_correction_history_guard ON sales_corrections AFTER INSERT, UPDATE, DELETE AS BEGIN SET NOCOUNT ON;
            IF EXISTS(SELECT 1 FROM deleted d LEFT JOIN inserted i ON i.id=d.id WHERE i.id IS NULL OR d.completed_at IS NOT NULL)
                THROW 51050, 'Correction history is immutable.', 1;
            IF EXISTS(SELECT id,sale_id,cash_session_id,reopened_by,reopened_at,observation COLLATE Latin1_General_100_BIN2,original_snapshot COLLATE Latin1_General_100_BIN2 FROM deleted EXCEPT SELECT id,sale_id,cash_session_id,reopened_by,reopened_at,observation COLLATE Latin1_General_100_BIN2,original_snapshot COLLATE Latin1_General_100_BIN2 FROM inserted)
                THROW 51050, 'Original correction evidence is immutable.', 1;
            IF EXISTS(SELECT 1 FROM inserted i JOIN sales_sales s ON s.id=i.sale_id JOIN cash_sessions c WITH (UPDLOCK,HOLDLOCK) ON c.id=i.cash_session_id WHERE s.status<>'closed' OR s.folio IS NULL OR c.status<>'open')
                THROW 51051, 'Correction requires an eligible sale and open original session.', 1;
            IF EXISTS(SELECT 1 FROM inserted WHERE applying_transaction_id IS NOT NULL AND applying_transaction_id<>CURRENT_TRANSACTION_ID())
                THROW 51052, 'Correction application belongs to another transaction.', 1;
            END");
        DB::unprepared("ALTER TRIGGER sales_closed_immutable ON sales_sales AFTER UPDATE, DELETE AS BEGIN SET NOCOUNT ON;
            IF EXISTS(SELECT 1 FROM deleted d LEFT JOIN inserted i ON i.id=d.id WHERE d.folio IS NOT NULL AND (i.id IS NULL OR NOT EXISTS(SELECT 1 FROM sales_corrections c WHERE c.sale_id=d.id AND c.status='active' AND c.completed_at IS NULL AND c.applying_transaction_id=CURRENT_TRANSACTION_ID())))
                THROW 51001, 'El cierre financiero requiere una corrección autorizada.', 1;
            IF EXISTS(SELECT id,type,status,table_id,created_by,cash_session_id,folio,closed_at,closed_by,created_at,cancelled_at,cancelled_by,cancellation_reason,cancellation_reason_id,cancellation_reason_snapshot FROM deleted WHERE folio IS NOT NULL EXCEPT SELECT id,type,status,table_id,created_by,cash_session_id,folio,closed_at,closed_by,created_at,cancelled_at,cancelled_by,cancellation_reason,cancellation_reason_id,cancellation_reason_snapshot FROM inserted)
                THROW 51001, 'La identidad y el primer cierre son inmutables.', 1;
            END");
        DB::unprepared("ALTER TRIGGER sales_paid_items_immutable ON sales_items AFTER INSERT, UPDATE, DELETE AS BEGIN SET NOCOUNT ON;
            IF EXISTS(SELECT 1 FROM sales_sales s JOIN (SELECT sale_id FROM inserted UNION SELECT sale_id FROM deleted) x ON x.sale_id=s.id WHERE s.folio IS NOT NULL AND NOT EXISTS(SELECT 1 FROM sales_corrections c WHERE c.sale_id=s.id AND c.status='active' AND c.completed_at IS NULL AND c.applying_transaction_id=CURRENT_TRANSACTION_ID()))
                THROW 51002, 'Las líneas financieras requieren corrección autorizada.', 1;
            IF EXISTS(SELECT d.id,d.sale_id,d.product_id,d.product_name,d.category_id,d.category_name,d.unit_price,d.position,d.created_at FROM deleted d JOIN sales_sales s ON s.id=d.sale_id WHERE s.folio IS NOT NULL EXCEPT SELECT id,sale_id,product_id,product_name,category_id,category_name,unit_price,position,created_at FROM inserted)
                THROW 51002, 'La instantánea original de producto es inmutable.', 1;
            END");
        DB::unprepared("CREATE TRIGGER sales_correction_payment_guard ON sales_payments AFTER INSERT AS BEGIN SET NOCOUNT ON;
            IF EXISTS(SELECT 1 FROM inserted i JOIN sales_sales s ON s.id=i.sale_id WHERE s.folio IS NOT NULL AND NOT EXISTS(SELECT 1 FROM sales_corrections c WHERE c.sale_id=s.id AND c.status='active' AND c.completed_at IS NULL AND c.applying_transaction_id=CURRENT_TRANSACTION_ID()))
                THROW 51053, 'A paid sale requires an explicit correction collection.', 1;
            END");
        DB::unprepared("CREATE TRIGGER sales_correction_cash_close_guard ON cash_sessions AFTER UPDATE AS BEGIN SET NOCOUNT ON;
            IF EXISTS(SELECT 1 FROM inserted i JOIN sales_corrections c WITH (UPDLOCK,HOLDLOCK) ON c.cash_session_id=i.id WHERE i.status='closed' AND c.completed_at IS NULL)
                THROW 51054, 'Resolve active sale corrections before closing Cash.', 1;
            END");
    }

    public function down(): void
    {
        if (! str_ends_with(strtolower(DB::selectOne('SELECT DB_NAME() AS name')->name), '_test')) {
            throw new RuntimeException('Rollback solo en bases _test.');
        }
        DB::unprepared('DROP TRIGGER IF EXISTS sales_correction_cash_close_guard; DROP TRIGGER IF EXISTS sales_correction_payment_guard; DROP TRIGGER IF EXISTS sales_correction_history_guard;');
        DB::unprepared("ALTER TRIGGER sales_closed_immutable ON sales_sales AFTER UPDATE, DELETE AS BEGIN SET NOCOUNT ON; IF EXISTS(SELECT 1 FROM deleted WHERE folio IS NOT NULL) THROW 51001, 'El cierre financiero es inmutable.', 1; END");
        DB::unprepared("ALTER TRIGGER sales_paid_items_immutable ON sales_items AFTER INSERT, UPDATE, DELETE AS BEGIN SET NOCOUNT ON; IF EXISTS(SELECT 1 FROM sales_sales s JOIN (SELECT sale_id FROM inserted UNION SELECT sale_id FROM deleted) x ON x.sale_id=s.id WHERE s.folio IS NOT NULL) THROW 51002, 'Las líneas financieras son inmutables.', 1; END");
        Schema::drop('sales_corrections');
    }
};
