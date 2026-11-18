<?php

namespace Modules\Core\Contracts;

use Illuminate\Support\Str;

final readonly class ReasonChangeAudit
{
    public ?array $before;

    public array $after;

    public function __construct(?array $before, array $after)
    {
        $this->before = $before === null ? null : self::snapshot($before);
        $this->after = self::snapshot($after);
        if ($this->before !== null && [$this->before['id'], $this->before['code'], $this->before['operation']] !== [$this->after['id'], $this->after['code'], $this->after['operation']]) {
            throw new \InvalidArgumentException('La identidad del motivo es inmutable.');
        }
    }

    public static function snapshot(array $data): array
    {
        unset($data['revision']);
        $fields = ['id', 'code', 'name', 'description', 'operation', 'is_active', 'display_order'];
        if (count($data) !== count($fields) || array_diff($fields, array_keys($data)) !== []
            || ! is_string($data['id']) || ! Str::isUlid($data['id'])
            || ! is_string($data['code']) || preg_match('/\A[A-Z0-9][A-Z0-9_:-]{0,63}\z/', $data['code']) !== 1
            || ! in_array($data['operation'], ['sale_item_removal', 'sale_cancellation'], true)
            || ! is_bool($data['is_active']) || ! is_int($data['display_order']) || $data['display_order'] < 0) {
            throw new \InvalidArgumentException('Instantánea de motivo inválida.');
        }
        foreach (['name' => 150, 'description' => 500] as $field => $maximum) {
            $value = $data[$field];
            if ($field === 'description' && $value === null) {
                continue;
            }
            if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8') || $field === 'name' && trim($value) === ''
                || preg_match('/[\p{Cc}\p{Cf}<>]/u', $value) || strlen(mb_convert_encoding($value, 'UTF-16LE', 'UTF-8')) > $maximum * 2) {
                throw new \InvalidArgumentException('Texto de motivo inválido.');
            }
        }

        return $data;
    }
}
