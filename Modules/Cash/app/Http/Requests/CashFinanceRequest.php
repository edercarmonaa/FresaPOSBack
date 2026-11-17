<?php

namespace Modules\Cash\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use Modules\Cash\Domain\MovementInput;
use Modules\Cash\Policies\CashPolicy;
use Modules\Core\Contracts\OperationIntent;
use Modules\Users\Contracts\IdentityAccess;

final class CashFinanceRequest extends FormRequest
{
    public function actor(): string
    {
        return (string) $this->user()->getAuthIdentifier();
    }

    public function authorize(): bool
    {
        $actor = app(IdentityAccess::class)->inspect($this->actor());
        $policy = app(CashPolicy::class);

        return $actor && $policy->allows($actor, 'cash.view') && (! $this->isMethod('POST') || $policy->allows($actor, 'cash.movements.create'));
    }

    public function rules(): array
    {
        if ($this->isMethod('POST')) {
            return ['cash_session_id' => ['required', 'ulid'], 'type' => ['required', 'in:cash_in,cash_out'], 'amount' => ['required', 'string', 'max:20'], 'concept' => ['required', 'string', 'max:200']];
        }

        return $this->route()->getActionMethod() === 'index' ? ['cash_session_id' => ['sometimes', 'ulid'], 'type' => ['sometimes', 'in:cash_in,cash_out'], 'date' => ['sometimes', 'date_format:Y-m-d'], 'created_by' => ['sometimes', 'ulid'], 'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'], 'per_page' => ['sometimes', 'integer', 'in:10,25,50,100']] : [];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), array_keys($this->rules())) !== []) {
                $validator->errors()->add('data', 'La solicitud contiene campos no permitidos.');
            }
            if (! $this->isMethod('POST')) {
                return;
            }
            if ($validator->errors()->isEmpty()) {
                OperationIntent::key((string) $this->header('Idempotency-Key'));
            }
            foreach (['amount', 'concept'] as $field) {
                if (! $validator->errors()->has($field)) {
                    try {
                        MovementInput::$field($this->input($field));
                    } catch (ValidationException $e) {
                        $validator->errors()->add($field, $e->errors()[$field][0]);
                    }
                }
            }
        }];
    }
}
