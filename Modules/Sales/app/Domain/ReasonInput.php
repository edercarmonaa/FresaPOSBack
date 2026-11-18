<?php

namespace Modules\Sales\Domain;

use Illuminate\Validation\ValidationException;

final class ReasonInput
{
    public const OPERATIONS = ['sale_item_removal', 'sale_cancellation'];

    public static function rules(string $action): array
    {
        return match ($action) {
            'create' => ['code' => 'required|string|regex:/\A[A-Z0-9][A-Z0-9_:-]{0,63}\z/', 'operation' => 'required|in:'.implode(',', self::OPERATIONS),
                'name' => 'required|string|max:150', 'description' => 'sometimes|nullable|string|max:500', 'display_order' => 'sometimes|integer|min:0|max:2147483647', 'is_active' => 'sometimes|boolean'],
            'update' => ['name' => 'required|string|max:150', 'description' => 'present|nullable|string|max:500', 'display_order' => 'required|integer|min:0|max:2147483647'],
            'status' => ['is_active' => 'required|boolean'],
            default => throw new \InvalidArgumentException('Operación de motivos inválida.'),
        };
    }

    public static function values(string $action, array $input): array
    {
        $rules = self::rules($action);
        if (array_diff(array_keys($input), array_keys($rules)) !== []) {
            throw ValidationException::withMessages(['data' => 'Campos no permitidos. El código y el tipo son inmutables.']);
        }
        $data = validator($input, $rules)->validate();
        try {
            if (isset($data['name'])) {
                $data['name'] = SalesText::normalize($data['name'], 150, true);
                $data['description'] = SalesText::normalize($data['description'] ?? null, 500);
                $data['display_order'] = (int) ($data['display_order'] ?? 0);
            }
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['data' => $exception->getMessage()]);
        }
        if ($action === 'create' || $action === 'status') {
            $data['is_active'] = (bool) ($data['is_active'] ?? true);
        }

        return $data;
    }
}
