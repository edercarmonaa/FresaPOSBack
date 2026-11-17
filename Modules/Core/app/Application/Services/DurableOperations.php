<?php

namespace Modules\Core\Application\Services;

use Closure;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;
use Modules\Core\Contracts\ApiCode;
use Modules\Core\Contracts\IdempotentOperations;
use Modules\Core\Contracts\OperationIntent;
use Modules\Core\Contracts\OperationRejected;
use Modules\Core\Contracts\OperationResult;
use Modules\Core\Contracts\OperationStatus;
use Modules\Core\Contracts\SecurityEvent;
use Modules\Core\Contracts\TransactionManager;
use Modules\Core\Infrastructure\Persistence\SqlOperationLock;
use Modules\Core\Infrastructure\Persistence\SqlOperationStore;
use Modules\Core\Infrastructure\Persistence\SqlTransientErrors;
use Throwable;

final class DurableOperations implements IdempotentOperations
{
    public function __construct(private readonly DatabaseManager $database, private readonly TransactionManager $transactions,
        private readonly SqlOperationLock $locks, private readonly SqlOperationStore $store, private readonly SqlTransientErrors $transients) {}

    public function run(OperationIntent $intent, Closure $authorize, Closure $operation): OperationResult
    {
        if ($this->database->connection()->transactionLevel() !== 0) {
            throw new \LogicException('El coordinador de idempotencia debe ser la transacción externa.');
        }
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            try {
                return $this->attempt($intent, $authorize, $operation);
            } catch (Throwable $exception) {
                if ($attempt === 5 || ! $this->transients->recoverable($exception)) {
                    throw $exception;
                }
                // La nueva conexión primero reclama el lock y consulta el recibo durable.
                // Nunca vuelve a ejecutar a ciegas un commit cuyo resultado se desconoce.
                if ($this->transients->reconnect($exception)) {
                    $this->database->purge($this->database->getDefaultConnection());
                }
                try {
                    Log::warning('Retrying durable operation.', ['attempt' => $attempt + 1, 'sql_code' => (int) (($exception->errorInfo ?? [])[1] ?? 0)]);
                } catch (Throwable) {
                    // Un diagnóstico fallido no altera la resolución del recibo durable.
                }
                usleep(100000 * (2 ** ($attempt - 1)));
            }
        }
        throw new \LogicException('Límite de reintentos alcanzado.');
    }

    private function attempt(OperationIntent $intent, Closure $authorize, Closure $operation): OperationResult
    {
        $authorize(false); // No espera el lock global para rechazar otra intención concurrente igual.
        $pdo = $this->database->connection()->getPdo();
        $resource = $this->locks->resource($intent->ownerContext, $intent->key);
        if (! $this->locks->acquire($pdo, $resource)) {
            throw new OperationRejected(ApiCode::OPERATION_IN_PROGRESS);
        }
        try {
            try {
                return $this->transactions->run(function () use ($intent, $authorize, $operation): OperationResult {
                    $authorize(true);
                    $row = $this->store->find($intent->ownerContext, $intent->key);
                    if ($row !== null) {
                        if (! hash_equals($row->request_hash, $intent->hash)) {
                            throw new OperationRejected(ApiCode::IDEMPOTENCY_KEY_CONFLICT, recordId: $row->id);
                        }
                        if ($this->store->status($row)->expired || $row->response === null && $row->state !== 'pending') {
                            throw new OperationRejected(ApiCode::IDEMPOTENCY_KEY_CONFLICT);
                        }
                        if ($row->state === 'pending') {
                            // No se apropia una fila pendiente introducida fuera del protocolo ACID.
                            throw new OperationRejected(ApiCode::OPERATION_IN_PROGRESS);
                        }
                        $response = json_decode($row->response, true, 512, JSON_THROW_ON_ERROR);
                        if ($row->state === 'failed') {
                            throw new OperationRejected(ApiCode::from($response['code']), $response['errors'] ?? []);
                        }

                        return new OperationResult((int) $row->http_status, $response['data'], $row->resource_id);
                    }
                    $id = $this->store->pending($intent);
                    $result = $operation();
                    $this->store->finish($id, $result);

                    return $result;
                });
            } catch (OperationRejected $exception) {
                if ($exception->apiCode === ApiCode::IDEMPOTENCY_KEY_CONFLICT && $exception->recordId !== null) {
                    event(new SecurityEvent('idempotency.key_conflict', actorId: $intent->auditActorId, recordId: $exception->recordId, result: 'blocked'));
                }
                if (in_array($exception->apiCode, [ApiCode::CONFLICT, ApiCode::NOT_FOUND, ApiCode::VALIDATION_ERROR], true)) {
                    // El negocio ya se revirtió. Solo persistir el fallo definitivo saneado.
                    $this->transactions->run(function () use ($intent, $authorize, $exception): void {
                        $authorize(true);
                        if ($this->store->find($intent->ownerContext, $intent->key) === null) {
                            $id = $this->store->pending($intent);
                            $this->store->fail($id, $exception->apiCode->status(), ['code' => $exception->apiCode->value, 'errors' => $exception->errors]);
                        }
                    });
                }
                throw $exception;
            }
        } finally {
            try {
                $this->locks->release($pdo, $resource);
            } catch (Throwable) {
                // Una conexión perdida libera su lock al finalizar la sesión SQL.
                // No convertir un commit confirmado en un fallo por liberar el lock.
            }
        }
    }

    public function status(string $ownerContext, string $key): OperationStatus
    {
        $key = OperationIntent::key($key);
        $pdo = $this->database->connection()->getPdo();
        $resource = $this->locks->resource($ownerContext, $key);
        if (! $this->locks->acquire($pdo, $resource, true)) {
            return new OperationStatus('pending');
        }
        try {
            $row = $this->store->find($ownerContext, $key);

            return $row === null ? new OperationStatus('not_found') : $this->store->status($row);
        } finally {
            $this->locks->release($pdo, $resource);
        }
    }
}
