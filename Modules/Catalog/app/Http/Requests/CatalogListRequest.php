<?php

namespace Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use Modules\Catalog\Http\CatalogAccess;
use Modules\Catalog\Http\CatalogFilters;
use Modules\Catalog\Http\CatalogValidation;

class CatalogListRequest extends FormRequest
{
    public function products(): bool
    {
        return $this->routeIs('api.v1.catalog.products.*');
    }

    public function authorize(): bool
    {
        $access = app(CatalogAccess::class);
        $access->requireAny($this->products() ? ['catalog.products.view'] : ['catalog.products.view', 'catalog.categories.manage'], $this);
        if (in_array($this->input('deleted'), ['with', 'only'], true)) {
            $access->trash($this->products(), $this);
        }

        return true;
    }

    protected function prepareForValidation(): void
    {
        if (in_array($this->input('is_active'), ['true', 'false'], true)) {
            $this->merge(['is_active' => $this->input('is_active') === 'true']);
        }
    }

    public function rules(): array
    {
        return $this->route('id') === null ? CatalogFilters::rules($this->products()) : ['deleted' => ['sometimes', 'in:without,with,only']];
    }

    public function messages(): array
    {
        return CatalogValidation::messages();
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), array_keys($this->rules())) !== []) {
                $validator->errors()->add('filters', 'Hay parámetros de consulta no permitidos.');
            }
            if ($validator->errors()->isNotEmpty() || $this->route('id') !== null) {
                return;
            }
            try {
                CatalogFilters::query($validator->validated(), $this->products());
            } catch (ValidationException $exception) {
                foreach ($exception->errors() as $field => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add($field, $message);
                    }
                }
            }
        }];
    }
}
