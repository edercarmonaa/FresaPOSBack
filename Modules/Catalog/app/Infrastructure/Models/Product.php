<?php

namespace Modules\Catalog\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property string $id
 * @property string $category_id
 * @property string $name
 * @property ?string $description
 * @property string $price
 * @property bool $is_active
 * @property ?string $image_disk
 * @property ?string $image_key
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property ?Carbon $deleted_at
 */
class Product extends Model
{
    use HasUlids, SoftDeletes;

    protected $table = 'catalog_products';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'price' => 'decimal:4'];
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id')->withTrashed();
    }

    protected static function booted(): void
    {
        static::forceDeleting(fn () => throw new LogicException('El catálogo conserva referencias históricas; no se permite borrado físico.'));
    }
}
