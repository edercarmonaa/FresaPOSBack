<?php

namespace Tests\Support;

use Modules\Tickets\Contracts\ReceiptData;
use Modules\Tickets\Contracts\SaleReceipts;

abstract class ReceiptsSqlServerTestCase extends CorrectionsSqlServerTestCase
{
    protected function receipt(string $id): ReceiptData
    {
        return app(SaleReceipts::class)->view($this->actor, $id);
    }

    protected function financialState(): array
    {
        $result = [];
        foreach (['sales_sales', 'sales_items', 'sales_payments', 'sales_daily_sequences', 'sales_corrections', 'cash_sessions',
            'cash_movements', 'cash_reconciliations', 'cash_registers', 'cash_payment_methods', 'sales_tables'] as $table) {
            $rows = array_map(fn ($row) => json_encode($row, JSON_THROW_ON_ERROR), $this->database->table($table)->get()->all());
            sort($rows);
            $result[$table] = hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
        }

        return $result;
    }
}
