<?php

namespace Modules\Cash\Infrastructure\Persistence;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Cash\Contracts\CashMovementSnapshot;
use Modules\Cash\Contracts\ConfirmedSalesCash;
use Modules\Cash\Contracts\PaymentMethodSnapshot;
use Modules\Core\Contracts\DecimalAmount;

final class FinancialStore
{
    public function __construct(private readonly ConfirmedSalesCash $salesCash) {}

    private function decimal(string $value): string
    {
        return DecimalAmount::from(str_starts_with($value, '.') ? '0'.$value : $value)->value;
    }

    public function transaction(): void
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new \LogicException('La persistencia financiera exige una transacción externa.');
        }
    }

    public function method(object $row): PaymentMethodSnapshot
    {
        return new PaymentMethodSnapshot($row->id, $row->code, $row->name, (bool) $row->is_active, (int) $row->display_order);
    }

    public function movement(object $row): CashMovementSnapshot
    {
        return new CashMovementSnapshot($row->id, $row->cash_session_id, $row->type, $this->decimal($row->amount), $row->concept, $row->created_by, Carbon::parse($row->occurred_at, 'UTC')->utc()->toISOString());
    }

    public function balance(string $sessionId, string $openingFund): array
    {
        $this->transaction();
        $openingFund = $this->decimal($openingFund);
        $income = $this->salesCash->total($sessionId);
        $row = DB::table('cash_movements')->where('cash_session_id', $sessionId)->selectRaw("COALESCE(SUM(CASE WHEN type='cash_in' THEN amount ELSE 0 END),0) AS cash_in, COALESCE(SUM(CASE WHEN type='cash_out' THEN amount ELSE 0 END),0) AS cash_out, COALESCE(SUM(CASE WHEN type='cash_in' THEN amount ELSE -amount END),0) + CAST(? AS decimal(19,4)) + CAST(? AS decimal(19,4)) AS expected", [$openingFund, $income])->first();

        return ['cash_session_id' => $sessionId, 'opening_fund' => $openingFund, 'cash_in' => $this->decimal($row->cash_in), 'cash_out' => $this->decimal($row->cash_out), 'confirmed_sales_cash' => $income, 'expected_cash' => $this->decimal($row->expected)];
    }

    public function insert(string $actorId, string $sessionId, string $type, string $amount, string $concept): CashMovementSnapshot
    {
        $this->transaction();
        $id = strtolower((string) Str::ulid());
        DB::table('cash_movements')->insert(['id' => $id, 'cash_session_id' => $sessionId, 'type' => $type, 'amount' => $amount, 'concept' => $concept, 'created_by' => $actorId, 'occurred_at' => now()->utc(), 'created_at' => now()->utc()]);

        return $this->movement(DB::table('cash_movements')->where('id', $id)->first());
    }
}
