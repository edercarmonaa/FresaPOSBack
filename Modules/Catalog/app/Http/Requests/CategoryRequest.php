<?php

namespace Modules\Catalog\Http\Requests;

use Modules\Catalog\Http\CatalogAccess;

class CategoryRequest extends CatalogWriteRequest
{
    public function authorize(): bool
    {
        app(CatalogAccess::class)->requireAny(['catalog.categories.manage'], $this);

        return true;
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:150'], 'description' => ['nullable', 'string', 'max:1000'],
            'display_order' => ['sometimes', 'integer', 'min:0', 'max:2147483647'], 'is_active' => ['sometimes', 'boolean']];
    }
}
