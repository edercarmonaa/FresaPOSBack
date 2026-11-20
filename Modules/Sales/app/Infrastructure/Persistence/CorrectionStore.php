<?php

namespace Modules\Sales\Infrastructure\Persistence;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Modules\Sales\Application\SaleSnapshotCodec;
use Modules\Sales\Contracts\SaleSnapshot;
use Modules\Sales\Domain\SalesConflict;

final class CorrectionStore
{
    public function active(string $saleId, bool $lock = false): array
    {
        $query = DB::table('sales_corrections')->where('sale_id', $saleId)->whereNull('completed_at');
        if ($lock) {
            $this->transaction();
            $query->lock('with (updlock,holdlock)');
        }
        $row = $query->first();
        if ($row === null) {
            throw new ModelNotFoundException;
        }

        return $this->decode((array) $row);
    }

    public function find(string $id): array
    {
        $row = DB::table('sales_corrections')->where('id', $id)->first();
        if ($row === null) {
            throw new ModelNotFoundException;
        }

        return $this->decode((array) $row);
    }

    public function exists(string $saleId): bool
    {
        $this->transaction();

        return DB::table('sales_corrections')->where('sale_id', $saleId)->whereNull('completed_at')->lock('with (updlock,holdlock)')->exists();
    }

    public function insert(array $values): array
    {
        $this->transaction();
        DB::table('sales_corrections')->insert($this->encode($values));

        return $this->find($values['id']);
    }

    public function update(string $id, array $values): array
    {
        $this->transaction();
        $changed = DB::table('sales_corrections')->where('id', $id)->whereNull('completed_at')->update($this->encode($values));
        if ($changed !== 1) {
            throw new SalesConflict('La corrección ya finalizó.');
        }

        return $this->find($id);
    }

    public function history(string $saleId, int $page): array
    {
        $query = DB::table('sales_corrections')->where('sale_id', $saleId);

        return ['items' => $query->orderByDesc('reopened_at')->orderByDesc('id')->forPage($page, 10)->get()->map(fn ($row) => $this->decode((array) $row))->all(),
            'total' => DB::table('sales_corrections')->where('sale_id', $saleId)->count(), 'page' => $page, 'per_page' => 10];
    }

    public function latestConfirmed(string $saleId): ?array
    {
        $this->transaction();
        $row = DB::table('sales_corrections')->where('sale_id', $saleId)->where('status', 'confirmed')
            ->orderByDesc('completed_at')->orderByDesc('id')->first(['id', 'completed_at']);

        return $row === null ? null : ['id' => $row->id, 'confirmed_at' => CarbonImmutable::parse($row->completed_at, 'UTC')->toISOString()];
    }

    private function decode(array $row): array
    {
        foreach (['original_snapshot', 'draft_snapshot', 'result_snapshot'] as $field) {
            if ($row[$field] !== null) {
                $row[$field] = json_decode($row[$field], true, 512, JSON_THROW_ON_ERROR);
            }
        }

        return $row;
    }

    private function encode(array $row): array
    {
        foreach (['original_snapshot', 'draft_snapshot', 'result_snapshot'] as $field) {
            if (isset($row[$field])) {
                $row[$field] = json_encode($row[$field], JSON_THROW_ON_ERROR);
            }
        }

        return $row;
    }

    public function transaction(): void
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new \LogicException('La corrección requiere la transacción externa de Core.');
        }
    }

    /** Ventana limitada a esta corrección y transacción SQL. Nunca cambia pagos existentes. */
    public function apply(array $correction, SaleSnapshot $draft): void
    {
        $this->transaction();
        $tx = DB::selectOne('SELECT CURRENT_TRANSACTION_ID() AS transaction_id')->transaction_id;
        $this->update($correction['id'], ['applying_transaction_id' => $tx]);
        $original = SaleSnapshotCodec::decode($correction['original_snapshot']);
        foreach ($draft->items as $item) {
            $previous = collect($original->items)->firstWhere('id', $item->id);
            if ($previous !== null && get_object_vars($previous) === get_object_vars($item)) {
                continue;
            }
            $values = ['quantity' => $item->quantity, 'note' => $item->note, 'line_total' => $item->lineTotal,
                'removed_at' => $item->removedAt === null ? null : CarbonImmutable::parse($item->removedAt)->utc(), 'removed_by' => $item->removedBy, 'removal_reason_code' => $item->removalReasonCode,
                'removal_reason_id' => $item->reasonSnapshot['id'] ?? null,
                'removal_reason_snapshot' => $item->reasonSnapshot === null ? null : json_encode($item->reasonSnapshot, JSON_THROW_ON_ERROR), 'updated_at' => now()->utc()];
            if ($previous === null) {
                DB::table('sales_items')->insert($values + ['id' => $item->id, 'sale_id' => $draft->sale->id, 'product_id' => $item->productId,
                    'product_name' => $item->name, 'category_id' => $item->categoryId, 'category_name' => $item->categoryName,
                    'unit_price' => $item->unitPrice, 'position' => $item->position, 'created_at' => now()->utc()]);
            } else {
                DB::table('sales_items')->where('sale_id', $draft->sale->id)->where('id', $item->id)->update($values);
            }
        }
        $auth = $draft->sale->discountAuthorization;
        DB::table('sales_sales')->where('id', $draft->sale->id)->update(['note' => $draft->sale->note,
            'subtotal' => $draft->sale->subtotal, 'discount_amount' => $draft->sale->discount, 'total' => $draft->sale->total,
            'discount_type' => $auth['type'] ?? null, 'discount_value' => $auth === null ? null : (string) $auth['value'],
            'discount_authorized_by' => $auth['authorized_by'] ?? null, 'discount_authorized_at' => $auth === null ? null : CarbonImmutable::parse($auth['authorized_at'])->utc(), 'updated_at' => now()->utc()]);
    }
}
