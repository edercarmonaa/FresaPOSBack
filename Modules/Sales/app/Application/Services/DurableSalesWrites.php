<?php

namespace Modules\Sales\Application\Services;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Modules\Core\Contracts\ApiCode;
use Modules\Core\Contracts\IdempotentOperations;
use Modules\Core\Contracts\OperationIntent;
use Modules\Core\Contracts\OperationRejected;
use Modules\Core\Contracts\OperationResult;
use Modules\Sales\Application\SaleSnapshotCodec;
use Modules\Sales\Contracts\CriticalSalesWrites;
use Modules\Sales\Contracts\SaleSnapshot;
use Modules\Sales\Domain\SalesConflict;
use Modules\Sales\Domain\SalesText;
use Modules\Sales\Policies\SalesPolicy;
use Modules\Users\Contracts\IdentityAccess;

final class DurableSalesWrites implements CriticalSalesWrites
{
    public function __construct(private readonly IdempotentOperations $operations, private readonly IdentityAccess $identities,
        private readonly SalesPolicy $policy) {}

    public function run(string $actorId, string $scope, string $key, array $payload, Closure $operation): SaleSnapshot
    {
        [$name, $method, $target] = $this->descriptor($scope);
        $permission = match ($name) {
            'open' => 'sales.create', 'cancel' => 'sales.cancel', default => 'sales.open.update'
        };
        $authorize = function (bool $locking) use ($actorId, $permission, $name): void {
            if ($locking) {
                $this->identities->lock();
            }
            $actor = $this->identities->inspect($actorId);
            if (! $actor || ! $this->policy->allows($actor, $permission) || ! $this->policy->allows($actor, 'sales.view')
                || $name === 'remove' && ! $this->policy->allows($actor, 'sales.special.authorize')) {
                throw new AuthorizationException;
            }
        };
        $authorize(false);
        $payload = $this->normalize($name, $payload);
        $intent = new OperationIntent(OperationIntent::context($actorId), $key, 'sales.'.$name, $method, $target, $payload, $actorId);
        $result = $this->operations->run($intent, $authorize, function () use ($operation, $name): OperationResult {
            try {
                $snapshot = $operation();

                return new OperationResult($name === 'open' ? 201 : 200, SaleSnapshotCodec::encode($snapshot), $snapshot->sale->id);
            } catch (ValidationException $exception) {
                throw new OperationRejected(ApiCode::VALIDATION_ERROR, $exception->errors());
            } catch (ModelNotFoundException) {
                throw new OperationRejected(ApiCode::NOT_FOUND);
            } catch (SalesConflict) {
                throw new OperationRejected(ApiCode::CONFLICT);
            }
        });

        return SaleSnapshotCodec::decode($result->data);
    }

    /** @return array{string,string,?string} */
    private function descriptor(string $scope): array
    {
        if ($scope === 'sales.open') {
            return ['open', 'POST', null];
        }
        if (preg_match('/\Asales\.([0-7][0-9a-hjkmnp-tv-z]{25})\.(add|quantity|item-note|note|pause|resume|remove|cancel)(?:\.([0-7][0-9a-hjkmnp-tv-z]{25}))?\z/i', $scope, $matches) !== 1
            || (in_array($matches[2], ['quantity', 'item-note', 'remove'], true) !== isset($matches[3]))) {
            throw new \InvalidArgumentException('Operación Sales no habilitada para idempotencia.');
        }
        $name = strtolower($matches[2]);

        return [$name, $name === 'remove' ? 'DELETE' : (in_array($name, ['add', 'pause', 'resume', 'cancel'], true) ? 'POST' : 'PATCH'), strtolower($matches[1].(isset($matches[3]) ? ':'.$matches[3] : ''))];
    }

    private function normalize(string $name, array $payload): array
    {
        try {
            foreach (['table_id', 'product_id'] as $field) {
                if (isset($payload[$field])) {
                    $payload[$field] = SalesText::id($payload[$field]);
                }
            }
            if (in_array($name, ['open', 'add', 'note', 'item-note'], true)) {
                $payload['note'] = SalesText::normalize($payload['note'] ?? null, in_array($name, ['add', 'item-note'], true) ? 100 : 150);
            }
            if ($name === 'open') {
                $payload['table_id'] ??= null;
            }
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['data' => $exception->getMessage()]);
        }

        return $payload;
    }
}
