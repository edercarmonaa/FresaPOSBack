<?php

namespace Modules\Reports\Domain;

use Illuminate\Validation\ValidationException;

final class ReportDefinition
{
    public const NAMES = ['summary' => 'Resumen', 'sales' => 'Ventas', 'products' => 'Productos vendidos',
        'payments' => 'Métodos de pago', 'cash-movements' => 'Movimientos de efectivo', 'cash-sessions' => 'Sesiones Cash',
        'cash-reconciliations' => 'Arqueos y cierres', 'discounts' => 'Descuentos', 'corrections' => 'Correcciones'];

    /** @return array<string,array{string,string}> clave => [etiqueta, tipo] */
    public static function columns(string $report): array
    {
        self::require($report);
        $definitions = [
            'summary' => ['business_date' => ['Día comercial', 'date'], 'paid_sales' => ['Ventas pagadas', 'integer'], 'income' => ['Ingreso confirmado', 'money'], 'discounts' => ['Descuentos', 'money']],
            'sales' => ['folio' => ['Folio', 'text'], 'occurred_at' => ['Fecha comercial', 'datetime'], 'type' => ['Tipo', 'text'], 'table_name' => ['Mesa (nombre actual)', 'text'], 'employee' => ['Empleado', 'text'], 'subtotal' => ['Subtotal', 'money'], 'discount_amount' => ['Descuento', 'money'], 'total' => ['Total vigente', 'money'], 'status' => ['Estado', 'text'], 'cash_session_id' => ['Sesión Cash', 'text'], 'corrected' => ['Corregida', 'boolean'], 'active_correction' => ['Corrección activa', 'boolean']],
            'products' => ['product_name' => ['Producto histórico', 'text'], 'product_id' => ['Producto ID', 'text'], 'category_name' => ['Categoría histórica', 'text'], 'quantity' => ['Cantidad', 'integer'], 'amount' => ['Importe bruto histórico', 'money'], 'share' => ['Participación %', 'decimal']],
            'payments' => ['method' => ['Método', 'text'], 'operations' => ['Aplicaciones confirmadas', 'integer'], 'amount' => ['Importe aplicado', 'money'], 'share' => ['Participación %', 'decimal']],
            'cash-movements' => ['occurred_at' => ['Fecha comercial', 'datetime'], 'cash_session_id' => ['Sesión Cash', 'text'], 'type' => ['Tipo', 'text'], 'concept' => ['Motivo / referencia registrada', 'text'], 'amount' => ['Importe', 'money'], 'employee' => ['Actor', 'text']],
            'cash-sessions' => ['cash_session_id' => ['Sesión Cash', 'text'], 'register_name' => ['Caja', 'text'], 'opened_at' => ['Apertura', 'datetime'], 'opened_employee' => ['Abrió', 'text'], 'opening_fund' => ['Fondo inicial', 'money'], 'status' => ['Estado', 'text'], 'closed_at' => ['Cierre', 'datetime'], 'closed_employee' => ['Cerró', 'text'], 'expected_cash' => ['Esperado (solo cierre)', 'money'], 'counted_cash' => ['Contado', 'money'], 'difference' => ['Diferencia', 'money']],
            'cash-reconciliations' => ['cash_session_id' => ['Sesión Cash', 'text'], 'closed_at' => ['Cierre', 'datetime'], 'opening_fund' => ['Fondo', 'money'], 'cash_in' => ['Entradas', 'money'], 'cash_out' => ['Salidas', 'money'], 'confirmed_sales_cash' => ['Ventas en efectivo netas', 'money'], 'expected_cash' => ['Esperado', 'money'], 'counted_cash' => ['Contado', 'money'], 'difference' => ['Diferencia firmada', 'money'], 'observation' => ['Observación', 'text'], 'closed_employee' => ['Cerró', 'text']],
            'discounts' => ['folio' => ['Folio', 'text'], 'occurred_at' => ['Primer cierre', 'datetime'], 'employee' => ['Empleado', 'text'], 'authorizer' => ['Autorizador vigente', 'text'], 'discount_type' => ['Tipo', 'text'], 'discount_value' => ['Valor autorizado', 'decimal'], 'discount_amount' => ['Descontado', 'money'], 'subtotal' => ['Subtotal', 'money'], 'total' => ['Total final', 'money']],
            'corrections' => ['folio' => ['Folio original', 'text'], 'original_at' => ['Primer cierre', 'datetime'], 'occurred_at' => ['Fecha de corrección', 'datetime'], 'authorizer' => ['Autorizador', 'text'], 'employee' => ['Confirmó / finalizó', 'text'], 'before_total' => ['Total anterior', 'money'], 'after_total' => ['Total confirmado posterior', 'money'], 'difference' => ['Cobro adicional confirmado', 'money'], 'status' => ['Estado', 'text'], 'observation' => ['Observación', 'text']],
        ];

        return $definitions[$report];
    }

    public static function filters(string $report): array
    {
        self::require($report);

        return match ($report) {
            'summary', 'sales', 'products', 'payments', 'discounts' => ['employee_id', 'sale_type', 'payment_method', 'cash_session_id', 'product_id', 'category_id', 'discount_type', ...($report === 'sales' ? ['state'] : [])],
            'corrections' => ['employee_id', 'cash_session_id', 'state'],
            'cash-movements' => ['employee_id', 'cash_session_id', 'movement_type'],
            'cash-sessions' => ['employee_id', 'cash_session_id', 'state'],
            'cash-reconciliations' => ['employee_id', 'cash_session_id'],
            default => throw ValidationException::withMessages(['report' => 'Reporte no disponible.']),
        };
    }

    public static function sorts(string $report): array
    {
        return match ($report) {
            'products' => ['quantity', 'amount', 'product_name'], 'payments' => ['amount', 'operations', 'method'],
            'summary' => ['business_date', 'income', 'paid_sales'], 'cash-sessions' => ['opened_at', 'closed_at', 'opening_fund'],
            'corrections' => ['occurred_at', 'folio'],
            'cash-reconciliations' => ['closed_at', 'difference', 'expected_cash'],
            'cash-movements' => ['occurred_at', 'amount'], default => ['occurred_at', 'total', 'folio'],
        };
    }

    public static function require(string $report): void
    {
        if (! isset(self::NAMES[$report])) {
            throw ValidationException::withMessages(['report' => 'Reporte no disponible.']);
        }
    }
}
