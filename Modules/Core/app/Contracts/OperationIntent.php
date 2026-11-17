<?php

namespace Modules\Core\Contracts;

final readonly class OperationIntent
{
    public string $key;

    public string $hash;

    public function __construct(public string $ownerContext, string $key, public string $operation, public string $method,
        public ?string $resourceId, array $parameters, public ?string $auditActorId = null)
    {
        $this->key = self::key($key);
        if (preg_match('/\A[a-f0-9]{64}\z/', $ownerContext) !== 1 || preg_match('/\A[a-z][a-z0-9_.:-]{0,95}\z/', $operation) !== 1
            || ! in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) || ($resourceId !== null && strlen($resourceId) > 128)) {
            throw new \InvalidArgumentException('Contexto de operación inválido.');
        }
        $this->hash = hash('sha256', json_encode(self::canonical([$operation, $method, $resourceId, $parameters]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public static function context(string $actorId): string
    {
        // Contexto opaco estable, no una FK ni un mecanismo de autorización.
        return hash('sha256', 'user:'.strtolower($actorId));
    }

    public static function key(string $key): string
    {
        if (preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $key) !== 1) {
            throw new OperationRejected(ApiCode::BAD_REQUEST, ['idempotency_key' => ['Envía Idempotency-Key con un UUID versión 4.']]);
        }

        return strtolower($key);
    }

    private static function canonical(mixed $value): mixed
    {
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }

            return array_map(self::canonical(...), $value);
        }
        if (is_object($value) || is_resource($value) || is_float($value)) {
            throw new \InvalidArgumentException('Usa parámetros escalares exactos o arrays para la intención.');
        }

        return $value;
    }
}
