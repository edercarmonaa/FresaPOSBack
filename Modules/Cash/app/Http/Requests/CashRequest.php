<?php

namespace Modules\Cash\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use Modules\Cash\Domain\OpeningFund;
use Modules\Cash\Policies\CashPolicy;
use Modules\Users\Contracts\IdentityAccess;

final class CashRequest extends FormRequest
{
    public function actor(): string
    {
        return (string) $this->user()->getAuthIdentifier();
    }

    public function authorize(): bool
    {
        $actor = app(IdentityAccess::class)->inspect($this->actor());

        // El fondo solo se entrega con cash.view, también al abrir.
        return $actor && app(CashPolicy::class)->allows($actor, 'cash.view')
            && (! $this->isMethod('POST') || app(CashPolicy::class)->allows($actor, 'cash.open'));
    }

    public function rules(): array
    {
        return $this->isMethod('POST') ? ['register_id' => ['required', 'ulid'], 'opening_fund' => ['required', 'string', 'max:20']] : [];
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
            if (preg_match('/\A[A-Za-z0-9_-]{16,128}\z/', (string) $this->header('Idempotency-Key')) !== 1) {
                $validator->errors()->add('idempotency_key', 'Envía una clave de 16 a 128 caracteres.');
            }
            if (! $validator->errors()->has('opening_fund')) {
                try {
                    OpeningFund::normalize($this->input('opening_fund'));
                } catch (ValidationException) {
                    $validator->errors()->add('opening_fund', 'El fondo debe ser texto decimal no negativo, con hasta cuatro decimales.');
                }
            }
        }];
    }
}
