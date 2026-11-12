<?php

namespace Modules\Catalog\Http\Requests;

use Modules\Catalog\Http\CatalogAccess;

class ProductRequest extends CatalogWriteRequest
{
    protected bool $products = true;

    public function authorize(): bool
    {
        $access = app(CatalogAccess::class);
        $access->requireAny(['catalog.products.view'], $this);
        $access->requireAny([$this->isMethod('POST') ? 'catalog.products.create' : 'catalog.products.update'], $this);
        if (! $this->isMethod('POST') && $this->exists('is_active')) {
            $access->requireAny(['catalog.products.deactivate'], $this);
        }

        return true;
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:150'], 'description' => ['nullable', 'string', 'max:2000'],
            'category_id' => ['required', 'ulid', 'exists:catalog_categories,id'], 'price' => ['required'],
            'is_active' => ['sometimes', 'boolean']];
    }
}
