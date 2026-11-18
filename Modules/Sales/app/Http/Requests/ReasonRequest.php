<?php

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Modules\Core\Contracts\OperationIntent;
use Modules\Sales\Domain\ReasonInput;
use Modules\Sales\Policies\SalesPolicy;
use Modules\Users\Contracts\IdentityAccess;

class ReasonRequest extends FormRequest
{
    public function action(): string
    {
        return (string) $this->route()?->getActionMethod();
    }

    public function actor(): string
    {
        return (string) $this->user()->getAuthIdentifier();
    }

    public function authorize(): bool
    {
        $actor = app(IdentityAccess::class)->inspect($this->actor());
        $policy = app(SalesPolicy::class);

        return $actor !== null && $policy->allows($actor, 'sales.view')
            && ($this->action() === 'index' || $policy->allows($actor, 'settings.update'));
    }

    protected function prepareForValidation(): void
    {
        if ($this->isMethod('GET')) {
            foreach (['administration', 'is_active'] as $field) {
                if (in_array($this->input($field), ['true', 'false'], true)) {
                    $this->merge([$field => $this->input($field) === 'true']);
                }
            }
        }
    }

    public function rules(): array
    {
        return match ($this->action()) {
            'index' => ['operation' => 'sometimes|in:'.implode(',', ReasonInput::OPERATIONS), 'administration' => 'sometimes|boolean',
                'is_active' => 'sometimes|boolean', 'page' => 'sometimes|integer|min:1|max:1000000', 'per_page' => 'sometimes|integer|in:10,25,50,100'],
            'show' => [],
            default => ReasonInput::rules($this->action()),
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
