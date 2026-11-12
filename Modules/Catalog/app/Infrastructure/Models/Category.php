<?php

namespace Modules\Catalog\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property string $id
 * @property string $name
 * @property ?string $description
 * @property bool $is_active
 * @property int $display_order
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property ?Carbon $deleted_at
 */
class Category extends Model
{
    use HasUlids, SoftDeletes;

    protected $table = 'catalog_categories';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'display_order' => 'integer'];
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'category_id');
    }

    protected static function booted(): void
    {
        static::forceDeleting(fn () => throw new LogicException('El catálogo conserva referencias históricas; no se permite borrado físico.'));
    }
}
