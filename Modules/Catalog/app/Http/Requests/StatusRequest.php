<?php

namespace Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\Catalog\Http\CatalogAccess;
use Modules\Catalog\Http\CatalogValidation;

class StatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        if ($this->routeIs('*.products.*')) {
            app(CatalogAccess::class)->requireAny(['catalog.products.view'], $this);
        }
        app(CatalogAccess::class)->requireAny([$this->routeIs('*.products.*') ? 'catalog.products.deactivate' : 'catalog.categories.manage'], $this);

        return true;
    }

    public function rules(): array
    {
        return ['is_active' => ['required', 'boolean']];
    }

    public function messages(): array
    {
        return CatalogValidation::messages();
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), ['is_active']) !== []) {
                $validator->errors()->add('data', 'La solicitud contiene campos no permitidos.');
            }
        }];
    }
}
