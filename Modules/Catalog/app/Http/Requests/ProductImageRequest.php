<?php

namespace Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\Catalog\Http\CatalogAccess;

class ProductImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        app(CatalogAccess::class)->requireAny(['catalog.products.view'], $this);
        app(CatalogAccess::class)->requireAny(['catalog.products.update'], $this);

        return true;
    }

    public function rules(): array
    {
        return ['image' => ['required', 'file', 'max:'.intdiv((int) config('catalog.images.max_bytes'), 1024)]];
    }

    public function messages(): array
    {
        return ['image.required' => 'Selecciona una imagen.', 'image.file' => 'El archivo no se recibió correctamente.', 'image.max' => 'La imagen no puede superar 5 MB.', 'image.uploaded' => 'No se pudo subir la imagen; revisa el límite de 5 MB.'];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), ['image']) !== []) {
                $validator->errors()->add('data', 'Solo se admite el archivo image.');
            }
        }];
    }
}
