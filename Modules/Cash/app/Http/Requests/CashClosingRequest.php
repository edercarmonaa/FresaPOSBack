<?php

namespace Modules\Cash\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\Cash\Policies\CashPolicy;
use Modules\Core\Contracts\OperationIntent;
use Modules\Users\Contracts\IdentityAccess;

final class CashClosingRequest extends FormRequest
{
    public function actor(): string
    {
        return (string) $this->user()->getAuthIdentifier();
    }

    public function authorize(): bool
    {
        $actor = app(IdentityAccess::class)->inspect($this->actor());
        $policy = app(CashPolicy::class);
        foreach (['cash.view', 'cash.closings.view', ...($this->isMethod('POST') ? ['cash.close'] : [])] as $permission) {
            if (! $actor || ! $policy->allows($actor, $permission)) {
                return false;
            }
        }

        return true;
    }

    public function rules(): array
    {
        return match ($this->route()->getActionMethod()) {
            'close' => ['counted_cash' => 'required|string|max:20', 'observation' => 'required|string|max:200'],
            'pending' => ['page' => 'sometimes|integer|min:1|max:1000000'],
            'history' => ['page' => 'sometimes|integer|min:1|max:1000000', 'per_page' => 'sometimes|integer|in:10,25,50,100',
                'date' => 'sometimes|date_format:Y-m-d', 'register_id' => 'sometimes|ulid', 'opened_by' => 'sometimes|ulid',
                'closed_by' => 'sometimes|ulid', 'status' => 'sometimes|in:open,closed'],
            default => [],
        };
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), array_keys($this->rules())) !== []) {
                $validator->errors()->add('data', 'La solicitud contiene campos no permitidos.');
            }
            if ($this->isMethod('POST') && $validator->errors()->isEmpty()) {
                OperationIntent::key((string) $this->header('Idempotency-Key'));
            }
        }];
    }
}
