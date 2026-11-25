<?php

namespace Modules\Reports\Application;

use Carbon\CarbonImmutable;

final class ReportPresentation
{
    public const METRICS = ['paid_sales' => 'Ventas pagadas', 'subtotal_total' => 'Subtotal confirmado', 'confirmed_income' => 'Ingreso confirmado',
        'discounts_total' => 'Descuento confirmado', 'average_ticket' => 'Ticket promedio (incluye ventas cero)', 'average_discount' => 'Promedio por venta descontada',
        'discounted_sales' => 'Ventas con descuento', 'zero_sales' => 'Ventas de total cero', 'counter_sales' => 'Ventas de mostrador', 'table_sales' => 'Ventas de mesa',
        'units_sold' => 'Unidades vendidas', 'gross_items_amount' => 'Importe bruto de productos', 'payments_total' => 'Pagos aplicados', 'reconciliation_difference' => 'Diferencia Sales menos pagos',
        'cash_applied' => 'Ventas en efectivo netas', 'card_applied' => 'Cobros con tarjeta', 'transfer_applied' => 'Cobros por transferencia', 'other_applied' => 'Otros cobros',
        'movements' => 'Movimientos manuales', 'manual_in' => 'Entradas manuales', 'manual_out' => 'Salidas manuales', 'manual_net' => 'Neto manual',
        'sessions' => 'Sesiones', 'closed_sessions' => 'Sesiones cerradas', 'open_sessions' => 'Sesiones abiertas', 'closed_cash_sessions' => 'Cierres confirmados',
        'closed_expected_cash' => 'Esperado de cierres', 'closed_counted_cash' => 'Contado de cierres', 'closing_differences' => 'Diferencia firmada de cierres',
        'corrections' => 'Correcciones registradas', 'confirmed_corrections' => 'Correcciones con cambios confirmados', 'additional_collected' => 'Cobro adicional confirmado'];

    public static function value(mixed $value, string $type, string $timezone): string
    {
        if ($value === null) {
            return '—';
        }
        if ($type === 'boolean') {
            return $value ? 'Sí' : 'No';
        }
        if ($type === 'datetime') {
            return CarbonImmutable::parse((string) $value, 'UTC')->setTimezone($timezone)->format('d/m/Y H:i:s');
        }
        if ($type === 'date') {
            return CarbonImmutable::parse((string) $value)->format('d/m/Y');
        }
        if ($type === 'money') {
            return '$'.(string) $value;
        }
        $labels = ['counter' => 'Mostrador', 'table' => 'Mesa', 'open' => 'Abierta', 'closed' => 'Cerrada', 'paused' => 'Pausada', 'cancelled' => 'Cancelada', 'active' => 'Activa',
            'confirmed' => 'Confirmada', 'unchanged' => 'Sin cambios', 'discarded' => 'Descartada', 'cash' => 'Efectivo', 'card' => 'Tarjeta', 'transfer' => 'Transferencia', 'other' => 'Otro autorizado',
            'cash_in' => 'Entrada', 'cash_out' => 'Salida', 'fixed' => 'Fijo', 'percent' => 'Porcentaje'];
        $labels += ['asc' => 'Ascendente', 'desc' => 'Descendente', 'occurred_at' => 'Fecha comercial', 'total' => 'Total', 'folio' => 'Folio',
            'quantity' => 'Cantidad', 'amount' => 'Importe', 'product_name' => 'Producto', 'operations' => 'Aplicaciones', 'method' => 'Método',
            'business_date' => 'Día comercial', 'income' => 'Ingreso', 'paid_sales' => 'Ventas pagadas', 'opened_at' => 'Apertura', 'closed_at' => 'Cierre',
            'opening_fund' => 'Fondo inicial', 'difference' => 'Diferencia', 'expected_cash' => 'Esperado'];

        return $type === 'text' ? ($labels[(string) $value] ?? (string) $value) : (string) $value;
    }

    public static function maximum(array $series, string $key): string
    {
        $max = '0.0000';
        foreach ($series as $row) {
            $value = (string) $row[$key];
            $a = ltrim(str_replace('.', '', $value), '0') ?: '0';
            $b = ltrim(str_replace('.', '', $max), '0') ?: '0';
            if (strlen($a) > strlen($b) || strlen($a) === strlen($b) && strcmp($a, $b) > 0) {
                $max = $value;
            }
        }

        return $max === '0.0000' ? '1.0000' : $max;
    }

    public static function filters(array $values): string
    {
        $labels = ['range' => 'Rango', 'from' => 'Desde', 'until' => 'Hasta', 'sort' => 'Orden', 'direction' => 'Dirección', 'employee_id' => 'Empleado ID', 'sale_type' => 'Tipo de venta',
            'payment_method' => 'Ventas que usaron el método', 'cash_session_id' => 'Sesión Cash', 'product_id' => 'Producto ID', 'category_id' => 'Categoría ID', 'discount_type' => 'Descuento', 'state' => 'Estado', 'movement_type' => 'Movimiento'];
        $result = [];
        foreach ($values as $key => $value) {
            if (in_array($key, ['page', 'per_page', 'range'], true)) {
                continue;
            }$result[] = ($labels[$key] ?? 'Criterio').': '.self::value($value, 'text', 'UTC');
        }

        return implode(' · ', $result);
    }
}
