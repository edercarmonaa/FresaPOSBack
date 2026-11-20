<?php

namespace Tests\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use RuntimeException;

/** Sonda de aceptación de solo lectura; no repara ni modifica registros. */
final class FinancialIntegrity
{
    public static function inspect(Connection $database): array
    {
        $name = $database->selectOne('SELECT DB_NAME() AS name')->name;
        if (! in_array($name, ['fresapos', 'fresapos_test'], true)) {
            throw new RuntimeException('Unexpected database for financial inspection.');
        }
        $queries = [
            'untrusted_foreign_keys' => 'SELECT COUNT(*) AS n FROM sys.foreign_keys WHERE is_disabled=1 OR is_not_trusted=1',
            'disabled_check_constraints' => 'SELECT COUNT(*) AS n FROM sys.check_constraints WHERE is_disabled=1 OR is_not_trusted=1',
            'disabled_financial_triggers' => "SELECT COUNT(*) AS n FROM sys.triggers WHERE is_disabled=1 AND (name LIKE 'cash_%' OR name LIKE 'sales_%')",
            'missing_financial_triggers' => "SELECT 9-COUNT(*) AS n FROM sys.triggers WHERE is_disabled=0 AND name IN ('sales_closed_immutable','sales_paid_items_immutable','sales_confirmed_payments_immutable','cash_reconciliation_immutable','cash_closed_session_immutable','cash_movement_session_guard','sales_cash_session_guard','sales_item_cash_session_guard','sales_payment_cash_session_guard')",
            'missing_unique_indexes' => "SELECT 5-COUNT(*) AS n FROM sys.indexes WHERE is_unique=1 AND is_disabled=0 AND name IN ('cash_one_open_session','sales_one_active_table','sales_unique_folio','idempotency_owner_key_unique','cash_reconciliations_cash_session_id_unique')",
            'incorrect_money_columns' => "SELECT 13-COUNT(*) AS n FROM sys.columns c JOIN sys.tables t ON t.object_id=c.object_id JOIN sys.types ty ON ty.user_type_id=c.user_type_id WHERE t.name IN ('cash_sessions','cash_movements','sales_sales','sales_items','sales_payments','cash_reconciliations') AND c.name IN ('opening_fund','amount','subtotal','discount_amount','total','unit_price','line_total','applied_amount','received_cash','change_cash','counted_cash','expected_cash','difference') AND ty.name='decimal' AND c.precision=19 AND c.scale=4",
            'duplicate_open_registers' => "SELECT COUNT(*) AS n FROM (SELECT register_id FROM cash_sessions WHERE status='open' GROUP BY register_id HAVING COUNT(*)>1) x",
            'duplicate_active_tables' => "SELECT COUNT(*) AS n FROM (SELECT table_id FROM sales_sales WHERE table_id IS NOT NULL AND status IN ('open','reopened') GROUP BY table_id HAVING COUNT(*)>1) x",
            'duplicate_folios' => 'SELECT COUNT(*) AS n FROM (SELECT folio FROM sales_sales WHERE folio IS NOT NULL GROUP BY folio HAVING COUNT(*)>1) x',
            'duplicate_reconciliations' => 'SELECT COUNT(*) AS n FROM (SELECT cash_session_id FROM cash_reconciliations GROUP BY cash_session_id HAVING COUNT(*)>1) x',
            'duplicate_intentions' => 'SELECT COUNT(*) AS n FROM (SELECT owner_context,idempotency_key FROM idempotency_records GROUP BY owner_context,idempotency_key HAVING COUNT(*)>1) x',
            'orphan_sales' => 'SELECT COUNT(*) AS n FROM sales_sales s LEFT JOIN cash_sessions c ON c.id=s.cash_session_id WHERE c.id IS NULL',
            'orphan_items' => 'SELECT COUNT(*) AS n FROM sales_items i LEFT JOIN sales_sales s ON s.id=i.sale_id WHERE s.id IS NULL',
            'incorrect_payment_session' => 'SELECT COUNT(*) AS n FROM sales_payments p LEFT JOIN sales_sales s ON s.id=p.sale_id AND s.cash_session_id=p.cash_session_id LEFT JOIN cash_payment_methods m ON m.id=p.payment_method_id WHERE s.id IS NULL OR m.id IS NULL',
            'confirmed_payment_without_close' => "SELECT COUNT(*) AS n FROM sales_payments p JOIN sales_sales s ON s.id=p.sale_id WHERE p.status='confirmed' AND (s.status<>'closed' OR s.folio IS NULL OR p.confirmed_at IS NULL)",
            'incorrect_closed_sale' => "SELECT COUNT(*) AS n FROM sales_sales s OUTER APPLY (SELECT SUM(line_total) AS subtotal FROM sales_items WHERE sale_id=s.id AND removed_at IS NULL) i OUTER APPLY (SELECT SUM(applied_amount) AS paid FROM sales_payments WHERE sale_id=s.id AND status='confirmed') p WHERE s.status='closed' AND (s.folio IS NULL OR s.closed_by IS NULL OR s.subtotal<>COALESCE(i.subtotal,0) OR s.total<>s.subtotal-s.discount_amount OR s.total<>COALESCE(p.paid,0))",
            'folio_on_open_sale' => "SELECT COUNT(*) AS n FROM sales_sales WHERE status IN ('open','paused') AND (folio IS NOT NULL OR closed_at IS NOT NULL)",
            'unresolved_sales_in_closed_session' => "SELECT COUNT(*) AS n FROM sales_sales s JOIN cash_sessions c ON c.id=s.cash_session_id WHERE c.status='closed' AND s.status IN ('open','paused','reopened')",
            'incorrect_closed_session' => "SELECT COUNT(*) AS n FROM cash_sessions c LEFT JOIN cash_reconciliations r ON r.cash_session_id=c.id WHERE (c.status='closed' AND (r.id IS NULL OR r.counted_by<>c.closed_by OR r.confirmed_at<>c.closed_at)) OR (c.status='open' AND r.id IS NOT NULL)",
            'incorrect_reconciliation' => "SELECT COUNT(*) AS n FROM cash_reconciliations r JOIN cash_sessions c ON c.id=r.cash_session_id OUTER APPLY (SELECT SUM(CASE WHEN type='cash_in' THEN amount ELSE -amount END) AS net FROM cash_movements WHERE cash_session_id=c.id) m OUTER APPLY (SELECT SUM(p.applied_amount) AS net FROM sales_payments p JOIN sales_sales s ON s.id=p.sale_id WHERE p.cash_session_id=c.id AND p.method_code='cash' AND p.status='confirmed' AND s.status='closed') p WHERE r.expected_cash<>c.opening_fund+COALESCE(m.net,0)+COALESCE(p.net,0) OR r.difference<>r.counted_cash-r.expected_cash OR TRY_CONVERT(decimal(19,4),JSON_VALUE(r.summary,'$.expected_cash'))<>r.expected_cash OR TRY_CONVERT(decimal(19,4),JSON_VALUE(r.summary,'$.counted_cash'))<>r.counted_cash OR TRY_CONVERT(decimal(19,4),JSON_VALUE(r.summary,'$.difference'))<>r.difference",
        ];
        $violations = [];
        if ($database->getSchemaBuilder()->hasTable('sales_corrections')) {
            $queries += [
                'missing_correction_guards' => "SELECT 3-COUNT(*) AS n FROM sys.triggers WHERE is_disabled=0 AND name IN ('sales_correction_history_guard','sales_correction_payment_guard','sales_correction_cash_close_guard')",
                'missing_correction_uniqueness' => "SELECT 1-COUNT(*) AS n FROM sys.indexes WHERE is_unique=1 AND is_disabled=0 AND name='sales_one_active_correction'",
                'duplicate_active_corrections' => 'SELECT COUNT(*) AS n FROM (SELECT sale_id FROM sales_corrections WHERE completed_at IS NULL GROUP BY sale_id HAVING COUNT(*)>1) x',
                'active_correction_in_closed_session' => "SELECT COUNT(*) AS n FROM sales_corrections x JOIN cash_sessions c ON c.id=x.cash_session_id WHERE x.completed_at IS NULL AND c.status<>'open'",
                'unfinished_correction_application' => 'SELECT COUNT(*) AS n FROM sales_corrections WHERE applying_transaction_id IS NOT NULL',
                'changed_correction_identity' => "SELECT COUNT(*) AS n FROM sales_corrections WHERE result_snapshot IS NOT NULL AND (JSON_VALUE(original_snapshot,'$.sale.id')<>JSON_VALUE(result_snapshot,'$.sale.id') OR JSON_VALUE(original_snapshot,'$.sale.folio')<>JSON_VALUE(result_snapshot,'$.sale.folio') OR JSON_VALUE(original_snapshot,'$.sale.closedAt')<>JSON_VALUE(result_snapshot,'$.sale.closedAt') OR JSON_VALUE(original_snapshot,'$.sale.createdAt')<>JSON_VALUE(result_snapshot,'$.sale.createdAt') OR JSON_VALUE(original_snapshot,'$.sale.cashSessionId')<>JSON_VALUE(result_snapshot,'$.sale.cashSessionId'))",
                'incorrect_correction_finance' => "SELECT COUNT(*) AS n FROM sales_corrections x OUTER APPLY (SELECT SUM(TRY_CONVERT(decimal(19,4),JSON_VALUE(value,'$.applied_amount'))) AS paid FROM OPENJSON(x.result_snapshot,'$.payments')) p WHERE x.result_snapshot IS NOT NULL AND (TRY_CONVERT(decimal(19,4),JSON_VALUE(x.result_snapshot,'$.sale.total'))<>COALESCE(p.paid,0) OR TRY_CONVERT(decimal(19,4),JSON_VALUE(x.result_snapshot,'$.sale.total'))<TRY_CONVERT(decimal(19,4),JSON_VALUE(x.original_snapshot,'$.sale.total')))",
            ];
        }
        foreach ($queries as $label => $sql) {
            $violations[$label] = (int) $database->selectOne($sql)->n;
        }
        $violations['invalid_ulids'] = 0;
        $ulidColumns = $database->select("SELECT t.name AS table_name,c.name AS column_name FROM sys.columns c JOIN sys.tables t ON t.object_id=c.object_id JOIN sys.types ty ON ty.user_type_id=c.user_type_id WHERE ty.name='nchar' AND c.max_length=52 AND t.is_ms_shipped=0");
        foreach ($ulidColumns as $column) {
            foreach ($database->table($column->table_name)->whereNotNull($column->column_name)->pluck($column->column_name) as $id) {
                if (! Str::isUlid($id)) {
                    $violations['invalid_ulids']++;
                }
            }
        }

        return ['database' => $name, 'read_only' => true, 'ulid_columns_checked' => count($ulidColumns),
            'violations' => $violations, 'passed' => array_filter($violations, fn (int $count): bool => $count !== 0) === []];
    }
}
