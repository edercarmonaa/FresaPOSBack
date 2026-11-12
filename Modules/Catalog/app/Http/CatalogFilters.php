<?php

namespace Modules\Catalog\Http;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Catalog\Contracts\CategoryQuery;
use Modules\Catalog\Contracts\ProductQuery;

final class CatalogFilters
{
    public static function rules(bool $products): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'], 'is_active' => ['nullable', 'boolean'],
            'deleted' => ['sometimes', 'in:without,with,only'],
            'sort' => ['sometimes', $products ? 'in:name,price' : 'in:display_order'],
            'direction' => ['sometimes', $products ? 'in:asc,desc' : 'in:asc'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', 'integer', 'in:10,25,50,100'],
            ...($products ? ['category_id' => ['nullable', 'ulid']] : []),
        ];
    }

    /** @return ($products is true ? ProductQuery : CategoryQuery) */
    public static function query(array $data, bool $products): ProductQuery|CategoryQuery
    {
        $data = Validator::make($data, self::rules($products), CatalogValidation::messages())->validate();
        try {
            $state = isset($data['is_active']) ? (bool) $data['is_active'] : null;
            if ($products) {
                return new ProductQuery($data['search'] ?? '', $data['category_id'] ?? null, $state, $data['deleted'] ?? 'without',
                    $data['sort'] ?? 'name', $data['direction'] ?? 'asc', (int) ($data['page'] ?? 1), (int) ($data['per_page'] ?? 25));
            }

            return new CategoryQuery($data['search'] ?? '', $state, $data['deleted'] ?? 'without', (int) ($data['page'] ?? 1), (int) ($data['per_page'] ?? 25));
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['search' => $exception->getMessage()]);
        }
    }
}
