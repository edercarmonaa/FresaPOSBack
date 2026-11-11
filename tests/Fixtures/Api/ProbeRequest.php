<?php

namespace Tests\Fixtures\Api;

use Modules\Core\Http\Requests\ApiFormRequest;

final class ProbeRequest extends ApiFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:20'], 'items' => ['required', 'array'], 'items.*.name' => ['required', 'string']];
    }

    public function attributes(): array
    {
        return ['name' => 'nombre', 'items.*.name' => 'nombre del elemento'];
    }
}
