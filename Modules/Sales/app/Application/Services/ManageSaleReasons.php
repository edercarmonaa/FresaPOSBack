<?php

namespace Modules\Sales\Application\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Modules\Core\Contracts\ApiCode;
use Modules\Core\Contracts\IdempotentOperations;
use Modules\Core\Contracts\OperationIntent;
use Modules\Core\Contracts\OperationRejected;
use Modules\Core\Contracts\OperationResult;
use Modules\Core\Contracts\ReasonChangeAudit;
use Modules\Core\Contracts\SecurityEvent;
use Modules\Core\Contracts\TransactionManager;
use Modules\Sales\Contracts\SaleOperationReasons;
use Modules\Sales\Domain\ReasonInput;
use Modules\Sales\Domain\SalesText;
use Modules\Sales\Infrastructure\Persistence\ReasonStore;
use Modules\Sales\Policies\SalesPolicy;
use Modules\Users\Contracts\IdentityAccess;

final class ManageSaleReasons implements SaleOperationReasons
{
    public function __construct(private readonly TransactionManager $transactions, private readonly IdempotentOperations $operations,
        private readonly IdentityAccess $identities, private readonly SalesPolicy $policy, private readonly ReasonStore $store) {}

    private function authorize(string $actorId, string $permission, bool $lock = false): void
    {
        if ($lock) {
            $this->identities->lock();
        }
        $actor = $this->identities->inspect($actorId);
        if (! $actor || ! $this->policy->allows($actor, $permission) || ! $this->policy->allows($actor, 'sales.view')) {
            throw new AuthorizationException;
        }
    }

    private function operationAccess(string $actorId, string $operation): void
    {
        $this->authorize($actorId, $operation === 'sale_item_removal' ? 'sales.special.authorize' : 'sales.cancel');
        if ($operation === 'sale_item_removal') {
            $this->authorize($actorId, 'sales.open.update');
        }
    }

    public function search(string $actorId, array $filters = []): array
    {
        return $this->transactions->run(function () use ($actorId, $filters): array {
            $this->authorize($actorId, 'sales.view', true);
            $rules = ['operation' => 'sometimes|in:'.implode(',', ReasonInput::OPERATIONS), 'administration' => 'sometimes|boolean',
                'is_active' => 'sometimes|boolean', 'page' => 'sometimes|integer|min:1|max:1000000', 'per_page' => 'sometimes|integer|in:10,25,50,100'];
            if (array_diff(array_keys($filters), array_keys($rules)) !== []) {
                throw ValidationException::withMessages(['filters' => 'Filtros no permitidos.']);
            }
            $filters = validator($filters, $rules)->validate();
            $admin = (bool) ($filters['administration'] ?? false);
            if ($admin) {
                $this->authorize($actorId, 'settings.update');
            } else {
                if (! isset($filters['operation']) || isset($filters['is_active'])) {
                    throw ValidationException::withMessages(['operation' => 'Selecciona una operación; las consultas operativas muestran solo motivos activos.']);
                }
                $this->operationAccess($actorId, $filters['operation']);
            }

            return $this->store->search($filters, $admin);
        });
    }

    public function find(string $actorId, string $id): array
    {
        return $this->transactions->run(function () use ($actorId, $id): array {
            $this->authorize($actorId, 'settings.update', true);

            return $this->store->find(SalesText::id($id));
        });
    }

    public function approved(string $actorId, string $operation, string $code): array
    {
        $this->store->transaction();
        $this->operationAccess($actorId, $operation);
        if (! in_array($operation, ReasonInput::OPERATIONS, true) || preg_match('/\A[A-Z0-9][A-Z0-9_:-]{0,63}\z/', $code) !== 1) {
            throw ValidationException::withMessages(['reason_code' => 'Selecciona un código autorizado.']);
        }

        return $this->store->approved($operation, $code);
    }

    public function write(string $actorId, string $action, array $data, string $key, ?string $id = null, ?string $revision = null): array
    {
        $this->authorize($actorId, 'settings.update');
        $values = ReasonInput::values($action, $data);
        $id = $id === null ? null : SalesText::id($id);
        if (($action === 'create') !== ($id === null)) {
            throw ValidationException::withMessages(['id' => 'Recurso de motivo inválido.']);
        }
        $intent = new OperationIntent(OperationIntent::context($actorId), $key, 'sales.reason.'.$action,
            match ($action) {
                'create' => 'POST', 'update' => 'PUT', default => 'PATCH'
            }, $id, ['values' => $values, 'revision' => $revision], $actorId);

        return $this->operations->run($intent, function (bool $lock) use ($actorId): void {
            $this->authorize($actorId, 'settings.update', $lock);
        }, function () use ($actorId, $action, $values, $id, $revision): OperationResult {
            try {
                $before = $id === null ? null : $this->store->find($id);
                if ($before !== null && ($revision === null || ! hash_equals('"'.$before['revision'].'"', $revision))) {
                    throw new OperationRejected(ApiCode::CONFLICT);
                }
                $after = $this->store->save($id, $values);
                $event = $action === 'create' ? 'created' : ($action === 'status' ? ($after['is_active'] ? 'activated' : 'deactivated') : 'updated');
                if ($before === null || array_diff_assoc($after, $before) !== []) {
                    event(new SecurityEvent('sales.reason.'.$event, actorId: $actorId, recordId: $after['id'], reasonChange: new ReasonChangeAudit($before, $after)));
                }

                return new OperationResult($action === 'create' ? 201 : 200, $after, $after['id']);
            } catch (ModelNotFoundException) {
                throw new OperationRejected(ApiCode::NOT_FOUND);
            } catch (QueryException $exception) {
                if (in_array((int) ($exception->errorInfo[1] ?? 0), [2601, 2627], true)) {
                    throw new OperationRejected(ApiCode::CONFLICT, ['reason' => ['Ya existe un código o nombre equivalente para esa operación.']]);
                }
                throw $exception;
            }
        })->data;
    }
}
