<?php

namespace Modules\Catalog\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use LogicException;
use Modules\Catalog\Infrastructure\Models\Category;
use Modules\Catalog\Infrastructure\Models\Product;

final class CatalogQueries
{
    public static function requireTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('El repositorio requiere una transacción coordinada por Application.');
        }
    }

    public static function names(Builder $query, string $name): void
    {
        if ($name === '') {
            return;
        }
        foreach (explode(' ', $name) as $word) {
            // Comodines literales; solo prefijos de palabras, no subcadenas arbitrarias.
            $word = str_replace(['[', '%', '_'], ['[[]', '[%]', '[_]'], $word);
            $query->where(fn (Builder $part) => $part->where('name', 'like', $word.'%')->orWhere('name', 'like', '% '.$word.'%'));
        }
    }

    /** @param Builder<Category>|Builder<Product> $query */
    public static function deleted(Builder $query, string $mode): void
    {
        if ($mode === 'with') {
            $query->withTrashed();
        } elseif ($mode === 'only') {
            $query->onlyTrashed();
        }
    }
}
