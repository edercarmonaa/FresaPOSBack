<?php

namespace Tests\Fixtures\UI07C;

use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Modules\Cash\Contracts\CashMovementSnapshot;
use Modules\Cash\Contracts\RegisterSnapshot;

final class FixtureData
{
    public const SESSION = '01ARZ3NDEKTSV4RRFFQ69G5FAV';

    public static function source(string $name, bool $legacy = false): string
    {
        $path = $legacy ? 'tests/Fixtures/UI07C/'.$name.'.blade.php' : 'Modules/Cash/resources/views/livewire/'.$name.'.blade.php';
        $source = file_get_contents(base_path($path));
        // El render aislado no ejecuta el script operativo ni monta hijos Livewire.
        $source = preg_replace('/@script\s*<script>.*?<\/script>\s*@endscript/s', '', $source);

        return preg_replace('/<livewire:cash\.(movements|closing)\s[^>]*\/>/', '<span data-fixture-child="$1"></span>', $source);
    }

    private static function errors(bool $withErrors): ViewErrorBag
    {
        return (new ViewErrorBag)->put('default', new MessageBag($withErrors ? ['amount' => ['El importe requiere revisión.'], 'concept' => ['Indica el concepto.']] : []));
    }

    public static function desk(string $state): array
    {
        return [
            'registers' => $state === 'unprovisioned' ? [] : [new RegisterSnapshot('01ARZ3NDEKTSV4RRFFQ69G5FAW', 'Caja de QA <script>alert("escaped")</script>', $state !== 'inactive')],
            'session' => in_array($state, ['open', 'blind', 'schema-missing'], true) ? ['id' => self::SESSION, 'opened_by' => '01ARZ3NDEKTSV4RRFFQ69G5FAX', 'opening_fund' => '100.0000', 'opened_at' => '2026-11-27T12:00:00Z'] : null,
            'responsible' => 'Responsable ficticio', 'counting' => $state === 'blind', 'canOpen' => $state !== 'read-only',
            'canViewClosings' => $state !== 'read-only', 'actorContext' => '01ARZ3NDEKTSV4RRFFQ69G5FAX',
            'status' => $state === 'status' ? 'Estado actualizado. Para otro turno registra una nueva apertura expresamente.' : '',
            'errors' => self::errors($state === 'error'), 'openingFund' => '0.0000',
        ];
    }

    public static function movements(string $state): array
    {
        $balance = in_array($state, ['no-session', 'pending'], true) ? null : ['cash_session_id' => self::SESSION, 'cash_in' => '10.2500', 'cash_out' => '0.2500', 'expected_cash' => '110.0000', 'confirmed_sales_cash' => '0.0000'];
        $items = in_array($state, ['no-session', 'pending', 'empty'], true) ? [] : [new CashMovementSnapshot('01ARZ3NDEKTSV4RRFFQ69G5FAY', self::SESSION, 'cash_in', '10.2500', 'Concepto ficticio <script>alert("escaped")</script>', '01ARZ3NDEKTSV4RRFFQ69G5FAX', '2026-11-27T13:00:00Z')];

        return [
            'status' => $state === 'pending' ? 'La operación sigue en proceso. Consulta su estado antes de preparar otra.' : '',
            'errors' => self::errors($state === 'error'), 'pending' => $state === 'pending', 'resolved' => $state === 'resolved',
            'canMove' => $state !== 'read-only', 'page' => $state === 'last-page' ? 2 : 1,
            'history' => ['items' => $items, 'balance' => $balance, 'total' => $items === [] ? 0 : 12],
            'movementType' => 'cash_in', 'amount' => '10.2500', 'concept' => 'Movimiento de fixture',
        ];
    }

    public static function closing(string $state): array
    {
        $receipt = [];
        if (in_array($state, ['balanced', 'shortage', 'surplus', 'no-payments'], true)) {
            $receipt = ['outcome' => $state === 'no-payments' ? 'balanced' : $state, 'register_name' => 'Caja de QA', 'cash_session_id' => self::SESSION, 'id' => '01ARZ3NDEKTSV4RRFFQ69G5FAZ', 'opened_by' => 'Responsable ficticio', 'opened_at' => '2026-11-27T12:00:00Z', 'closed_by' => 'Responsable ficticio', 'closed_at' => '2026-11-27T18:00:00Z', 'opening_fund' => '100.0000', 'closed_sales' => $state === 'no-payments' ? 0 : 1, 'total_sold' => $state === 'no-payments' ? '0.0000' : '50.0000', 'confirmed_sales_cash' => '0.0000', 'cash_in' => '0.0000', 'cash_out' => '0.0000', 'expected_cash' => '100.0000', 'counted_cash' => ['shortage' => '99.0000', 'surplus' => '101.0000'][$state] ?? '100.0000', 'difference' => ['shortage' => '-1.0000', 'surplus' => '1.0000'][$state] ?? '0.0000', 'observation' => 'Observación ficticia <script>alert("escaped")</script>', 'payment_methods' => $state === 'no-payments' ? [] : [['method_code' => 'card', 'applied_amount' => '50.0000', 'payment_count' => 1]]];
        }
        $items = in_array($state, ['empty', 'pending'], true) ? [] : [['register_name' => 'Caja de QA', 'cash_session_id' => '01ARZ3NDEKTSV4RRFFQ69G5FB0', 'closed_at' => '2026-11-26T18:00:00Z', 'expected_cash' => '75.0000', 'counted_cash' => '74.0000', 'difference' => '-1.0000']];
        $page = $state === 'last-page' ? 2 : 1;

        return [
            'errors' => self::errors($state === 'error'), 'canClose' => $state !== 'read-only',
            'pending' => $state === 'pending', 'key' => in_array($state, ['blind', 'pending'], true) ? 'qa-fixture-intent' : '',
            'pendingTotal' => $state === 'blocked' ? 26 : 0,
            'pendingSales' => $state === 'blocked' ? [['id' => '01ARZ3NDEKTSV4RRFFQ69G5FB1', 'type' => 'table', 'status' => 'correcting', 'table_id' => '01ARZ3NDEKTSV4RRFFQ69G5FB2']] : [],
            'formOpen' => in_array($state, ['blind', 'pending', 'error'], true), 'receipt' => $receipt, 'cashSessionId' => self::SESSION,
            'history' => ['items' => $items, 'total' => $items === [] ? 0 : 12, 'page' => $page], 'page' => $page,
            'countedCash' => '', 'observation' => '', 'date' => '', 'closedBy' => '', 'registerId' => '', 'historyStatus' => 'closed',
        ];
    }
}
