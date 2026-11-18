<?php

namespace Modules\Cash\Infrastructure\Persistence;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class ClosingStore
{
    public function transaction(): void
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new \LogicException('El cierre requiere una transacción Core externa.');
        }
    }

    public function session(string $id): object
    {
        $this->transaction();
        $row = DB::table('cash_sessions')->where('id', $id)->lock('with (updlock,holdlock)')->first();
        if (! $row) {
            throw new ModelNotFoundException;
        }

        return $row;
    }

    public function find(string $sessionId): array
    {
        $this->transaction();
        $row = DB::table('cash_reconciliations')->where('cash_session_id', $sessionId)->first();
        if (! $row) {
            throw new ModelNotFoundException;
        }

        return json_decode($row->summary, true, 512, JSON_THROW_ON_ERROR);
    }

    public function persist(array $summary): void
    {
        $this->transaction();
        $at = CarbonImmutable::parse($summary['closed_at']);
        DB::table('cash_reconciliations')->insert(['id' => $summary['id'], 'cash_session_id' => $summary['cash_session_id'],
            'counted_by' => $summary['closed_by'], 'counted_cash' => $summary['counted_cash'], 'expected_cash' => $summary['expected_cash'],
            'difference' => $summary['difference'], 'observation' => $summary['observation'], 'confirmed_at' => $at,
            'summary' => json_encode($summary, JSON_THROW_ON_ERROR), 'created_at' => $at, 'updated_at' => $at]);
        $updated = DB::table('cash_sessions')->where('id', $summary['cash_session_id'])->where('status', 'open')
            ->update(['status' => 'closed', 'closed_by' => $summary['closed_by'], 'closed_at' => $at, 'updated_at' => $at]);
        if ($updated !== 1) {
            throw new \LogicException('La sesión cambió durante el cierre.');
        }
    }

    public function history(array $filters): array
    {
        $this->transaction();
        $query = DB::table('cash_reconciliations as r')->join('cash_sessions as s', 's.id', '=', 'r.cash_session_id');
        foreach (['register_id', 'opened_by', 'closed_by', 'status'] as $field) {
            if (isset($filters[$field])) {
                $query->where('s.'.$field, $filters[$field]);
            }
        }
        if (isset($filters['date'])) {
            $date = Carbon::createFromFormat('!Y-m-d', $filters['date'], 'UTC');
            $query->where('r.confirmed_at', '>=', $date)->where('r.confirmed_at', '<', $date->copy()->addDay());
        }
        $total = $query->count();
        $page = $filters['page'] ?? 1;
        $perPage = $filters['per_page'] ?? 25;
        $items = $query->select('r.summary')->orderByDesc('r.confirmed_at')->orderByDesc('r.id')->forPage($page, $perPage)->get()
            ->map(fn ($row) => json_decode($row->summary, true, 512, JSON_THROW_ON_ERROR))->all();

        return compact('items', 'page', 'perPage', 'total');
    }
}
