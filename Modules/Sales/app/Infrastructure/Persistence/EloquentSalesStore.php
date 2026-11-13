<?php

namespace Modules\Sales\Infrastructure\Persistence;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Sales\Application\Ports\SalesStore;
use Modules\Sales\Contracts\DiningTableSnapshot;
use Modules\Sales\Contracts\SaleItemSnapshot;
use Modules\Sales\Contracts\SaleQuery;
use Modules\Sales\Contracts\SaleSnapshot;
use Modules\Sales\Contracts\SalesPage;
use Modules\Sales\Contracts\SaleSummary;
use Modules\Sales\Contracts\TableQuery;
use Modules\Sales\Domain\SaleState;
use Modules\Sales\Domain\SaleType;
use Modules\Sales\Infrastructure\Models\DiningTable;
use Modules\Sales\Infrastructure\Models\Sale;
use Modules\Sales\Infrastructure\Models\SaleItem;

final class EloquentSalesStore implements SalesStore
{
    public function findSale(string $id, bool $lock = false): SaleSnapshot
    {
        $query = Sale::query();
        if ($lock) {
            $this->requireTransaction();
            $query->lockForUpdate();
        }
        $model = $query->findOrFail($id);

        return new SaleSnapshot($this->summary($model), $model->items()->get()->map(fn (SaleItem $item) => $this->item($item))->all());
    }

    public function createSale(array $values): SaleSnapshot
    {
        $this->requireTransaction();
        $model = Sale::query()->create($values);

        return $this->findSale($model->id);
    }

    public function updateSale(string $id, array $values): SaleSnapshot
    {
        $this->requireTransaction();
        Sale::query()->findOrFail($id)->fill($values)->save();

        return $this->findSale($id);
    }

    public function createItem(string $saleId, array $values): SaleItemSnapshot
    {
        $this->requireTransaction();

        return $this->item(SaleItem::query()->create(['sale_id' => $saleId, ...$values]));
    }

    public function updateItem(string $saleId, string $itemId, array $values): void
    {
        $this->requireTransaction();
        SaleItem::query()->where('sale_id', $saleId)->whereNull('removed_at')->findOrFail($itemId)->fill($values)->save();
    }

    public function sales(SaleQuery $query): SalesPage
    {
        $builder = Sale::query();
        foreach (['status' => $query->state?->value, 'type' => $query->type?->value, 'table_id' => $query->tableId, 'created_by' => $query->createdBy] as $column => $value) {
            if ($value !== null) {
                $builder->where($column, $value);
            }
        }
        if ($query->date !== null) {
            $from = CarbonImmutable::parse($query->date, 'UTC')->startOfDay();
            $builder->where('created_at', '>=', $from)->where('created_at', '<', $from->addDay());
        }
        $page = $builder->orderBy('created_at', $query->order)->orderBy('id', $query->order)->paginate($query->perPage, ['*'], 'page', $query->page);

        return new SalesPage($page->getCollection()->map(fn (Sale $sale) => $this->summary($sale))->all(), $page->total(), $query->page, $query->perPage);
    }

    public function findTable(string $id, bool $lock = false): DiningTableSnapshot
    {
        $builder = $this->tableQuery();
        if ($lock) {
            $this->requireTransaction();
            $builder->lockForUpdate();
        }

        return $this->tableSnapshot($builder->findOrFail($id));
    }

    public function createTable(array $values): DiningTableSnapshot
    {
        $this->requireTransaction();
        $model = DiningTable::query()->create($values);

        return $this->findTable($model->id);
    }

    public function updateTable(string $id, array $values): DiningTableSnapshot
    {
        $this->requireTransaction();
        DiningTable::query()->findOrFail($id)->fill($values)->save();

        return $this->findTable($id);
    }

    public function tables(TableQuery $query): SalesPage
    {
        $builder = $this->tableQuery();
        if ($query->search !== null) {
            $escaped = str_replace(['[', '%', '_'], ['[[]', '[%]', '[_]'], $query->search);
            $builder->where('name', 'like', '%'.$escaped.'%');
        }
        if ($query->isActive !== null) {
            $builder->where('is_active', $query->isActive);
        }
        if ($query->isOccupied !== null) {
            $builder->has('sales', $query->isOccupied ? '>=' : '<', 1, 'and', fn (Builder $sales) => $sales->whereIn('status', ['open', 'reopened']));
        }
        $page = $builder->orderBy('name')->orderBy('id')->paginate($query->perPage, ['*'], 'page', $query->page);

        return new SalesPage($page->getCollection()->map(fn (DiningTable $table) => $this->tableSnapshot($table))->all(), $page->total(), $query->page, $query->perPage);
    }

    /** @return Builder<DiningTable> */
    private function tableQuery(): Builder
    {
        return DiningTable::query()->withExists(['sales as is_occupied' => fn (Builder $query) => $query->whereIn('status', ['open', 'reopened'])])
            ->addSelect(['active_sale_id' => Sale::query()->select('id')->whereColumn('table_id', 'sales_tables.id')->whereIn('status', ['open', 'reopened'])->limit(1)]);
    }

    private function tableSnapshot(DiningTable $table): DiningTableSnapshot
    {
        return new DiningTableSnapshot($table->id, $table->name, $table->is_active, $table->display_order, (bool) $table->getAttribute('is_occupied'), $table->getAttribute('active_sale_id'));
    }

    private function summary(Sale $sale): SaleSummary
    {
        return new SaleSummary($sale->id, SaleType::from($sale->type), SaleState::from($sale->status), $sale->table_id,
            $sale->created_by, $sale->cash_session_id, $sale->note, $sale->subtotal, $sale->discount_amount, $sale->total,
            $sale->folio, $sale->closed_at?->toISOString(), $sale->cancelled_at?->toISOString(), $sale->cancelled_by,
            $sale->cancellation_reason, $sale->created_at->toISOString(), $sale->updated_at->toISOString());
    }

    private function item(SaleItem $item): SaleItemSnapshot
    {
        return new SaleItemSnapshot($item->id, $item->sale_id, $item->product_id, $item->product_name, $item->category_id,
            $item->category_name, $item->unit_price, $item->quantity, $item->note, $item->line_total, $item->position,
            $item->removed_at?->toISOString(), $item->removed_by, $item->removal_reason_code);
    }

    private function requireTransaction(): void
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new LogicException('El repositorio requiere una transacción coordinada por la aplicación.');
        }
    }
}
