<?php

namespace Modules\Sales\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sales\Domain\SalesConflict;
use Modules\Sales\Domain\SalesDependencyUnavailable;

final class ReasonStore
{
    public function transaction(): void
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new \LogicException('Los motivos requieren una transacción externa.');
        }
    }

    public function available(): bool
    {
        return DB::connection()->getSchemaBuilder()->hasTable('sales_operation_reasons');
    }

    public function snapshot(object $row): array
    {
        $data = ['id' => $row->id, 'code' => $row->code, 'name' => $row->name, 'description' => $row->description,
            'operation' => $row->operation, 'is_active' => (bool) $row->is_active, 'display_order' => (int) $row->display_order];

        return $data + ['revision' => hash_hmac('sha256', json_encode($data, JSON_THROW_ON_ERROR), (string) config('app.key'))];
    }

    public function find(string $id): array
    {
        $this->transaction();
        $row = DB::table('sales_operation_reasons')->where('id', $id)->lock('with (updlock,holdlock)')->first();
        if ($row === null) {
            throw new ModelNotFoundException;
        }

        return $this->snapshot($row);
    }

    public function approved(string $operation, string $code): array
    {
        $this->transaction();
        if (! $this->available()) {
            throw new SalesDependencyUnavailable('Configura el catálogo autorizado de motivos antes de operar.');
        }
        $row = DB::table('sales_operation_reasons')->where('code', $code)->where('operation', $operation)->where('is_active', true)->lock('with (updlock,holdlock)')->first();
        if ($row === null) {
            throw new SalesConflict('Selecciona un motivo activo y compatible con la operación.');
        }

        return $this->snapshot($row);
    }

    public function search(array $filters, bool $admin): array
    {
        $this->transaction();
        if (! $this->available()) {
            return ['items' => [], 'total' => 0, 'page' => 1, 'per_page' => 25];
        }
        $query = DB::table('sales_operation_reasons');
        if (isset($filters['operation'])) {
            $query->where('operation', $filters['operation']);
        }
        if (! $admin || isset($filters['is_active'])) {
            $query->where('is_active', $admin ? $filters['is_active'] : true);
        }
        $page = (int) ($filters['page'] ?? 1);
        $size = (int) ($filters['per_page'] ?? 25);
        $total = $query->count();
        $items = $query->orderBy('display_order')->orderBy('name')->orderBy('id')->forPage($page, $size)->get()->map(fn ($row) => $this->snapshot($row))->all();

        return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $size];
    }

    public function save(?string $id, array $data): array
    {
        $this->transaction();
        if ($id === null) {
            $id = strtolower((string) Str::ulid());
            DB::table('sales_operation_reasons')->insert($data + ['id' => $id, 'created_at' => now()->utc(), 'updated_at' => now()->utc()]);
        } else {
            DB::table('sales_operation_reasons')->where('id', $id)->update($data + ['updated_at' => now()->utc()]);
        }

        return $this->find($id);
    }
}
