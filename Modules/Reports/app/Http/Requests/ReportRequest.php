<?php

namespace Modules\Reports\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Modules\Reports\Domain\ReportCriteria;

final class ReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ReportCriteria::rules((string) $this->route('report', 'summary'));
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (array_diff(array_keys($this->except('_token')), array_keys($this->rules())) !== []) {
                $validator->errors()->add('filters', 'Hay filtros no aplicables o campos desconocidos.');
            }
        });
    }

    protected function failedValidation(Validator $validator): void
    {
        if (! $this->is('api/v1/*')) {
            throw new HttpResponseException(response()->view('reports::error', ['message' => 'Revisa los filtros del reporte.', 'validation' => $validator->errors()->messages()], 422));
        }
        parent::failedValidation($validator);
    }

    public function criteria(): array
    {
        $values = $this->except('_token');
        ReportCriteria::from((string) $this->route('report', 'summary'), $values);

        return $values;
    }
}
