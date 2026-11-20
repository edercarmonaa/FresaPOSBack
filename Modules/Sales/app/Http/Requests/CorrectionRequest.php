<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\Core\Contracts\OperationIntent;
use Modules\Sales\Application\Services\CorrectPaidSale;
use Modules\Sales\Policies\SalesPolicy;
use Modules\Users\Contracts\IdentityAccess;

class CorrectionRequest extends FormRequest
{
    public function actor(): string
    {
        return (string) $this->user()->getAuthIdentifier();
    }

    public function authorize(): bool
    {
        $actor = app(IdentityAccess::class)->inspect($this->actor());
        $permissions = ['sales.view', ...($this->isMethod('GET') ? [] : ['sales.reopen', 'sales.special.authorize', 'sales.open.update', 'cash.view']),
            ...($this->route()?->getActionMethod() === 'confirm' ? ['sales.close'] : [])];
        foreach ($permissions as $permission) {
            if ($actor === null || ! app(SalesPolicy::class)->allows($actor, $permission)) {
                return false;
            }
        }

        return true;
    }

    public function rules(): array
    {
        return match ($this->route()?->getActionMethod()) {
            'reopen' => ['observation' => 'sometimes|nullable|string|max:150'],
            'edit' => app(CorrectPaidSale::class)->editRules($this->input('action')),
            'confirm' => ['payments' => 'present|array|list|max:20'],
            'history' => ['page' => 'sometimes|integer|min:1|max:1000000'], default => [],
        };
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), array_keys($this->rules())) !== []) {
                $validator->errors()->add('data', 'Campos no permitidos.');
            }
            if (! $this->isMethod('GET') && $validator->errors()->isEmpty()) {
                OperationIntent::key((string) $this->header('Idempotency-Key'));
            }
        }];
    }
}
