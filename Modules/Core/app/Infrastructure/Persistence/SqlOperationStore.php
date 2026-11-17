<?php

namespace Modules\Core\Infrastructure\Persistence;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use Modules\Core\Contracts\OperationIntent;
use Modules\Core\Contracts\OperationResult;
use Modules\Core\Contracts\OperationStatus;
use stdClass;

final class SqlOperationStore
{
    public function __construct(private readonly DatabaseManager $database) {}

    public function find(string $context, string $key): ?stdClass
    {
        return $this->database->connection()->table('idempotency_records')->where('owner_context', $context)->where('idempotency_key', $key)->first();
    }

    public function pending(OperationIntent $intent): string
    {
        $this->transaction();
        $id = strtolower((string) Str::ulid());
        $this->database->connection()->table('idempotency_records')->insert(['id' => $id, 'owner_context' => $intent->ownerContext,
            'idempotency_key' => $intent->key, 'operation' => $intent->operation, 'resource_id' => $intent->resourceId, 'request_hash' => $intent->hash,
            'state' => 'pending', 'created_at' => now()->utc()]);

        return $id;
    }

    public function finish(string $id, OperationResult $result): void
    {
        $this->terminal($id, 'completed', $result->httpStatus, ['data' => $result->data], $result->resourceId);
    }

    public function fail(string $id, int $status, array $response): void
    {
        $this->terminal($id, 'failed', $status, $response);
    }

    private function terminal(string $id, string $state, int $status, array $response, ?string $resourceId = null): void
    {
        $this->transaction();
        $hours = max(1, min(8760, (int) config('core.idempotency.retention_hours', 24)));
        $values = ['state' => $state, 'http_status' => $status,
            'response' => json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'completed_at' => now()->utc(), 'expires_at' => now()->utc()->addHours($hours)];
        if ($state === 'completed') {
            $values['resource_id'] = $resourceId;
        }
        $this->database->connection()->table('idempotency_records')->where('id', $id)->update($values);
    }

    public function status(stdClass $row): OperationStatus
    {
        $expired = $row->expires_at !== null && CarbonImmutable::parse($row->expires_at, 'UTC')->lessThanOrEqualTo(now()->utc());

        return new OperationStatus($row->state, $expired, $row->operation, $row->resource_id,
            $row->http_status === null ? null : (int) $row->http_status,
            $row->completed_at === null ? null : CarbonImmutable::parse($row->completed_at, 'UTC')->toISOString());
    }

    public function compact(int $batch = 50): int
    {
        $this->transaction();

        // Una sentencia acotada; READPAST evita esperar filas reclamadas por ejecuciones.
        return $this->database->connection()->affectingStatement("UPDATE TOP (?) idempotency_records WITH (READPAST, READCOMMITTEDLOCK) SET response=NULL WHERE state IN ('completed','failed') AND expires_at<=? AND response IS NOT NULL", [max(1, min(100, $batch)), now()->utc()]);
    }

    private function transaction(): void
    {
        if ($this->database->connection()->transactionLevel() < 1) {
            throw new \LogicException('El repositorio de operaciones requiere una transacción Core.');
        }
    }
}
