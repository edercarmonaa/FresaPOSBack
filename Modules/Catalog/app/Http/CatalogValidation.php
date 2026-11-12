<?php

namespace Modules\Catalog\Http;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\Catalog\Application\CatalogInput;

final class CatalogValidation
{
    public static function messages(): array
    {
        return ['required' => 'El campo :attribute es obligatorio.', 'string' => 'El campo :attribute debe ser texto.',
            'max' => 'El campo :attribute supera el máximo permitido (:max).', 'min' => 'El campo :attribute debe ser al menos :min.',
            'integer' => 'El campo :attribute debe ser un entero.', 'boolean' => 'El campo :attribute debe ser booleano.',
            'in' => 'El valor de :attribute no está permitido.', 'ulid' => 'El campo :attribute debe ser un ULID válido.',
            'exists' => 'La categoría no existe.', 'unique' => 'Ya existe una categoría equivalente.'];
    }

    public static function data(array $data, bool $products, ?string $id = null): array
    {
        // Única normalización de negocio: delegada a 1.4a, también utilizada por los formularios Web.
        $normalized = $products ? CatalogInput::product($data, $id === null) : CatalogInput::category($data, $id === null);
        if (! $products && isset($normalized['name'])) {
            $unique = Rule::unique('catalog_categories', 'name');
            if ($id !== null) {
                $unique->ignore($id, 'id');
            }
            Validator::make(['name' => $normalized['name']], ['name' => [$unique]], self::messages())->validate();
        }

        return $normalized;
    }
}
