<?php

namespace Modules\Sales\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Modules\Sales\Domain\SaleState;
use Modules\Sales\Policies\SalesPolicy;
use Modules\Users\Contracts\IdentityAccess;

class SalesRequest extends FormRequest
{
    public function operation(): string
    {
        return (string) $this->route()?->getActionMethod();
    }

    public function tables(): bool
    {
        return $this->routeIs('api.v1.sales.tables.*');
    }

    public function actor(): string
    {
        return (string) $this->user()->getAuthIdentifier();
    }

    public function authorize(): bool
    {
        $operation = $this->operation();
        $permission = in_array($operation, ['index', 'show', 'items'], true) ? 'sales.view'
            : ($this->tables() ? 'settings.update' : match ($operation) {
                'store' => 'sales.create', 'cancel' => 'sales.cancel', default => 'sales.open.update',
            });
        $actor = app(IdentityAccess::class)->inspect($this->actor());
        $policy = app(SalesPolicy::class);
        if ($actor === null || ! $policy->allows($actor, $permission)) {
            return false;
        }

        return $operation !== 'removeItem' || $policy->allows($actor, 'sales.special.authorize');
    }

    protected function prepareForValidation(): void
    {
        if ($this->isMethod('GET')) {
            foreach (['is_active', 'is_occupied'] as $key) {
                if (in_array($this->input($key), ['true', 'false'], true)) {
                    $this->merge([$key => $this->input($key) === 'true']);
                }
            }
        }
    }

    public function rules(): array
    {
        $note = ['present', 'nullable', 'string', 'max:'.($this->operation() === 'itemNote' ? 100 : 150)];
        $quantity = ['required', function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_int($value) || $value < 1 || $value > (int) config('sales.max_quantity')) {
                $fail('La cantidad debe ser un entero positivo dentro del límite configurado.');
            }
        }];
        $page = ['page' => ['sometimes', 'integer', 'min:1', 'max:1000000'], 'per_page' => ['sometimes', 'integer', Rule::in([10, 25, 50, 100])]];
        if ($this->operation() === 'index') {
            return $page + ($this->tables()
                ? ['search' => ['nullable', 'string', 'max:100'], 'is_active' => ['sometimes', 'boolean'], 'is_occupied' => ['sometimes', 'boolean']]
                : ['state' => ['sometimes', Rule::enum(SaleState::class)], 'type' => ['sometimes', 'in:counter,table'], 'table_id' => ['sometimes', 'ulid'],
                    'created_by' => ['sometimes', 'ulid'], 'date' => ['sometimes', 'date_format:Y-m-d'], 'order' => ['sometimes', 'in:asc,desc']]);
        }
        if ($this->tables()) {
            return match ($this->operation()) {
                'store' => ['name' => ['required', 'string', 'max:150'], 'display_order' => ['sometimes', 'integer', 'min:0', 'max:2147483647']],
                'update' => ['name' => ['required', 'string', 'max:150'], 'is_active' => ['required', 'boolean'], 'display_order' => ['sometimes', 'integer', 'min:0', 'max:2147483647']],
                'status' => ['is_active' => ['required', 'boolean']], default => [],
            };
        }

        return match ($this->operation()) {
            'store' => ['type' => ['required', 'in:counter,table'], 'table_id' => ['required_if:type,table', 'prohibited_if:type,counter', 'ulid'], 'note' => ['sometimes', 'nullable', 'string', 'max:150']],
            'addItem' => ['product_id' => ['required', 'ulid'], 'quantity' => $quantity, 'note' => ['sometimes', 'nullable', 'string', 'max:100']],
            'quantity' => ['quantity' => $quantity], 'itemNote', 'note' => ['note' => $note],
            'removeItem', 'cancel' => ['reason_code' => ['required', 'string', 'regex:/\A[A-Z0-9][A-Z0-9_:-]{0,63}\z/']], default => [],
        };
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), array_keys($this->rules())) !== []) {
                $validator->errors()->add('data', 'La solicitud contiene campos no permitidos.');
            }
            if (in_array($this->operation(), ['store', 'addItem'], true) && ! $this->tables()
                && preg_match('/\A[A-Za-z0-9_-]{16,128}\z/', (string) $this->header('Idempotency-Key')) !== 1) {
                $validator->errors()->add('idempotency_key', 'Envía una clave de idempotencia de 16 a 128 caracteres.');
            }
        }];
    }
}
