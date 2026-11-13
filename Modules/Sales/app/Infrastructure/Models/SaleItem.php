<?php

namespace Modules\Sales\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property string $id
 * @property string $sale_id
 * @property string $product_id
 * @property string $product_name
 * @property string $category_id
 * @property string $category_name
 * @property string $unit_price
 * @property int $quantity
 * @property ?string $note
 * @property string $line_total
 * @property int $position
 * @property ?Carbon $removed_at
 * @property ?string $removed_by
 * @property ?string $removal_reason_code
 */
class SaleItem extends Model
{
    use HasUlids;

    protected $table = 'sales_items';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['unit_price' => 'decimal:4', 'line_total' => 'decimal:4', 'quantity' => 'integer', 'position' => 'integer', 'removed_at' => 'datetime'];
    }

    /** @return BelongsTo<Sale, $this> */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Las líneas se retiran mediante autorización; no se borran.'));
    }
}
