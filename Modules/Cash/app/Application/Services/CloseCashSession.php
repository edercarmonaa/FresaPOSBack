<?php

namespace Modules\Cash\Application\Services;

use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Cash\Application\Ports\CashStore;
use Modules\Cash\Contracts\CashClosings;
use Modules\Cash\Contracts\SessionSales;
use Modules\Cash\Domain\ClosingInput;
use Modules\Cash\Infrastructure\Persistence\ClosingStore;
use Modules\Cash\Infrastructure\Persistence\FinancialStore;
use Modules\Cash\Policies\CashPolicy;
use Modules\Core\Contracts\ApiCode;
use Modules\Core\Contracts\CashClosingAudit;
use Modules\Core\Contracts\IdempotentOperations;
use Modules\Core\Contracts\OperationIntent;
use Modules\Core\Contracts\OperationRejected;
use Modules\Core\Contracts\OperationResult;
use Modules\Core\Contracts\SecurityEvent;
use Modules\Core\Contracts\TransactionManager;
use Modules\Users\Contracts\IdentityAccess;
use Modules\Users\Contracts\UserSnapshot;

final class CloseCashSession implements CashClosings
{
    public function __construct(private readonly TransactionManager $transactions, private readonly IdempotentOperations $operations,
        private readonly IdentityAccess $identities, private readonly CashPolicy $policy, private readonly CashStore $cash,
        private readonly FinancialStore $finance, private readonly SessionSales $sales, private readonly ClosingStore $store) {}

    private function authorize(string $actorId, bool $write, bool $locking): UserSnapshot
    {
        if ($locking) {
            $this->identities->lock();
        }
        $actor = $this->identities->inspect($actorId);
        foreach (['cash.view', 'cash.closings.view', ...($write ? ['cash.close'] : [])] as $permission) {
            if (! $actor || ! $this->policy->allows($actor, $permission)) {
                throw new AuthorizationException;
            }
        }

        return $actor;
    }

    private function id(string $id): string
    {
        if (! Str::isUlid($id)) {
            throw new ModelNotFoundException;
        }

        return strtolower($id);
    }

    private function lockedSession(string $sessionId): object
    {
        // Mismo protocolo que Sales y movimientos: identidad -> caja -> sesión.
        $this->cash->active();

        return $this->store->session($sessionId);
    }

    public function pending(string $actorId, string $sessionId, int $page = 1): array
    {
        return $this->transactions->run(function () use ($actorId, $sessionId, $page): array {
            $actor = $this->authorize($actorId, true, true);
            if (! in_array('sales.view', $actor->permissions, true)) {
                throw new AuthorizationException;
            }
            if ($page < 1 || $page > 1000000) {
                throw ValidationException::withMessages(['page' => 'Página inválida.']);
            }
            $row = $this->lockedSession($this->id($sessionId));
            if ($row->status !== 'open') {
                throw new OperationRejected(ApiCode::CONFLICT);
            }

            return $this->sales->pending($row->id, $page) + ['page' => $page, 'per_page' => 25];
        });
    }

    public function close(string $actorId, string $sessionId, mixed $countedCash, mixed $observation, string $key): array
    {
        $this->authorize($actorId, true, false);
        $sessionId = $this->id($sessionId);
        $counted = ClosingInput::counted($countedCash);
        $observation = ClosingInput::observation($observation);
        // Siempre obligatoria: un rechazo dependiente de la diferencia sería un oráculo del saldo ciego.
        if ($observation === null) {
            throw ValidationException::withMessages(['observation' => 'Describe brevemente el conteo antes de confirmarlo.']);
        }
        $intent = new OperationIntent(OperationIntent::context($actorId), $key, 'cash.session.close', 'POST', $sessionId, compact('counted', 'observation'), $actorId);
        $result = $this->operations->run($intent, function (bool $locking) use ($actorId): void {
            $this->authorize($actorId, true, $locking);
        }, function () use ($actorId, $sessionId, $counted, $observation): OperationResult {
            try {
                $session = $this->lockedSession($sessionId);
                if ($session->status !== 'open') {
                    throw new OperationRejected(ApiCode::CONFLICT, ['cash_session_id' => ['La sesión ya está cerrada. Consulta su arqueo histórico.']]);
                }
                $pending = $this->sales->pending($sessionId);
                if ($pending['total'] > 0) {
                    // Los IDs se consultan en pending con sales.view; el rechazo no filtra importes ni recursos ajenos.
                    throw new OperationRejected(ApiCode::CONFLICT, ['pending_sales' => ['Hay ventas abiertas, pausadas o correcciones activas. Resuélvelas en el POS y prepara otra intención de cierre.']]);
                }
                $balance = $this->finance->balance($sessionId, $session->opening_fund);
                $sales = $this->sales->confirmed($sessionId);
                $difference = ClosingInput::difference($counted, $balance['expected_cash']);
                $register = $this->cash->register($session->register_id);
                $closedAt = CarbonImmutable::now('UTC')->startOfSecond()->toISOString();
                $summary = ['id' => strtolower((string) Str::ulid()), 'cash_session_id' => $sessionId, 'register_id' => $register->id,
                    'register_name' => $register->name, 'opened_by' => $session->opened_by,
                    'opened_at' => CarbonImmutable::parse($session->opened_at, 'UTC')->toISOString(),
                    'closed_by' => $actorId, 'closed_at' => $closedAt, 'status' => 'closed',
                    ...$balance, ...$sales, 'counted_cash' => $counted, 'difference' => $difference,
                    'outcome' => $difference === '0.0000' ? 'balanced' : (str_starts_with($difference, '-') ? 'shortage' : 'surplus'),
                    'observation' => $observation];
                $this->store->persist($summary);
                $audit = new CashClosingAudit($sessionId, $register->id, $summary['id'], $balance['expected_cash'], $counted, $difference);
                event(new SecurityEvent('cash.reconciliation.confirmed', actorId: $actorId, recordId: $summary['id'], closing: $audit));
                if ($difference !== '0.0000') {
                    event(new SecurityEvent('cash.difference.detected', actorId: $actorId, recordId: $summary['id'], closing: $audit));
                }
                event(new SecurityEvent('cash.session.closed', actorId: $actorId, recordId: $sessionId, closing: $audit));

                return new OperationResult(200, $summary, $sessionId);
            } catch (ModelNotFoundException) {
                throw new OperationRejected(ApiCode::NOT_FOUND);
            }
        });

        return $result->data;
    }

    public function reconciliation(string $actorId, string $sessionId): array
    {
        return $this->transactions->run(function () use ($actorId, $sessionId): array {
            $this->authorize($actorId, false, true);
            $session = $this->lockedSession($this->id($sessionId));
            if ($session->status !== 'closed') {
                throw new OperationRejected(ApiCode::CONFLICT, ['cash_session_id' => ['El resultado solo está disponible después del cierre confirmado.']]);
            }

            return $this->store->find($session->id);
        });
    }

    public function history(string $actorId, array $filters = []): array
    {
        return $this->transactions->run(function () use ($actorId, $filters): array {
            $this->authorize($actorId, false, true);
            $validator = validator($filters, ['page' => 'sometimes|integer|min:1|max:1000000', 'per_page' => 'sometimes|integer|in:10,25,50,100',
                'date' => 'sometimes|date_format:Y-m-d', 'register_id' => 'sometimes|ulid', 'opened_by' => 'sometimes|ulid',
                'closed_by' => 'sometimes|ulid', 'status' => 'sometimes|in:open,closed']);
            if (array_diff(array_keys($filters), array_keys($validator->getRules())) !== []) {
                throw ValidationException::withMessages(['filters' => 'Filtros no permitidos.']);
            }
            $filters = $validator->validate();
            foreach (['page', 'per_page'] as $field) {
                if (isset($filters[$field])) {
                    $filters[$field] = (int) $filters[$field];
                }
            }

            // Historial de cierres: open devuelve una página vacía; no calcula saldos de sesiones activas.
            return $this->store->history($filters);
        });
    }
}
