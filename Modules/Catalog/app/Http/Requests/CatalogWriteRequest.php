<?php

namespace Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use Modules\Catalog\Http\CatalogValidation;

abstract class CatalogWriteRequest extends FormRequest
{
    abstract public function rules(): array;

    protected bool $products = false;

    private array $catalogValues = [];

    public function messages(): array
    {
        return CatalogValidation::messages();
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            if (array_diff(array_keys($this->all()), array_keys($this->rules())) !== []) {
                $validator->errors()->add('data', 'La solicitud contiene campos no permitidos.');

                return;
            }
            $values = $validator->validated();
            if (array_key_exists('is_active', $values)) {
                $values['is_active'] = (bool) $values['is_active'];
            }
            if (array_key_exists('display_order', $values)) {
                $values['display_order'] = (int) $values['display_order'];
            }
            try {
                $this->catalogValues = CatalogValidation::data($values, $this->products, $this->route('id'));
            } catch (ValidationException $exception) {
                foreach ($exception->errors() as $field => $messages) {
                    foreach ($messages as $message) {
                        $validator->errors()->add($field, $message);
                    }
                }
            }
        }];
    }

    public function catalogData(): array
    {
        return $this->catalogValues;
    }
}
