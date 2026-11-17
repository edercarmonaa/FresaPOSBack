<?php

namespace Modules\Cash\Application\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Cash\Application\Ports\CashStore;
use Modules\Cash\Contracts\CashMovements;
use Modules\Cash\Contracts\CashMovementSnapshot;
use Modules\Cash\Contracts\SessionSnapshot;
use Modules\Cash\Domain\MovementInput;
use Modules\Cash\Infrastructure\Persistence\FinancialStore;
use Modules\Cash\Policies\CashPolicy;
use Modules\Core\Contracts\ApiCode;
use Modules\Core\Contracts\CashMovementAudit;
use Modules\Core\Contracts\DecimalAmount;
use Modules\Core\Contracts\IdempotentOperations;
use Modules\Core\Contracts\OperationIntent;
use Modules\Core\Contracts\OperationRejected;
use Modules\Core\Contracts\OperationResult;
use Modules\Core\Contracts\SecurityEvent;
use Modules\Core\Contracts\TransactionManager;
use Modules\Users\Contracts\IdentityAccess;
use Modules\Users\Contracts\UserSnapshot;

final class ManageCashMovements implements CashMovements
{
    public function __construct(private readonly TransactionManager $transactions, private readonly IdentityAccess $identities, private readonly CashPolicy $policy, private readonly CashStore $cash, private readonly FinancialStore $store, private readonly IdempotentOperations $operations) {}

    private function authorize(string $actorId, bool $write, bool $locking = true): UserSnapshot
    {
        if ($locking) {
            $this->identities->lock();
        }
        $actor = $this->identities->inspect($actorId);
        if (! $actor || ! $this->policy->allows($actor, 'cash.view') || $write && ! $this->policy->allows($actor, 'cash.movements.create')) {
            throw new AuthorizationException;
        }

        return $actor;
    }

    public function record(string $actorId, string $sessionId, string $type, mixed $amount, mixed $concept, string $key): CashMovementSnapshot
    {
        $this->authorize($actorId, true, false);
        if (! Str::isUlid($sessionId) || ! in_array($type, ['cash_in', 'cash_out'], true)) {
            throw ValidationException::withMessages(['data' => 'Sesión o tipo de movimiento inválido.']);
        }
        $sessionId = strtolower($sessionId);
        $amount = MovementInput::amount($amount);
        $concept = MovementInput::concept($concept);
        $intent = new OperationIntent(OperationIntent::context($actorId), $key, 'cash.movement.'.$type, 'POST', $sessionId, compact('sessionId', 'type', 'amount', 'concept'), $actorId);
        $result = $this->operations->run($intent, function (bool $locking) use ($actorId): void {
            $this->authorize($actorId, true, $locking);
        }, function () use ($actorId, $sessionId, $type, $amount, $concept): OperationResult {
            $session = $this->cash->active();
            if (! $session || ! $session->register->isActive || $session->id !== $sessionId) {
                throw new OperationRejected(ApiCode::CONFLICT, ['cash_session_id' => ['La sesión indicada no está abierta. Actualiza el estado de caja.']]);
            }
            try {
                $balance = $this->store->balance($session->id, $session->openingFund);
                $available = DecimalAmount::from($balance['expected_cash']);
                $money = DecimalAmount::from($amount);
                if ($type === 'cash_out') {
                    $available->subtract($money);
                } else {
                    $available->add($money);
                }
                DecimalAmount::from($balance[$type])->add($money);
            } catch (\InvalidArgumentException) {
                throw new OperationRejected(ApiCode::CONFLICT, ['amount' => ['Saldo insuficiente o importe fuera del rango financiero permitido.']]);
            }
            $movement = $this->store->insert($actorId, $sessionId, $type, $amount, $concept);
            event(new SecurityEvent('cash.movement.recorded', actorId: $actorId, recordId: $movement->id, cashMovement: new CashMovementAudit($sessionId, $type, $amount)));

            return new OperationResult(201, $movement->toArray(), $movement->id);
        });

        return CashMovementSnapshot::fromArray($result->data);
    }

    private function selected(UserSnapshot $actor, ?string $id): ?SessionSnapshot
    {
        $current = $this->cash->active();
        if ($id === null || $current?->id === strtolower($id)) {
            return $current;
        }
        if (! Str::isUlid($id) || ! $this->policy->allows($actor, 'cash.administrative.execute')) {
            throw new ModelNotFoundException;
        }
        $row = DB::table('cash_sessions')->where('id', strtolower($id))->lock('with (updlock,holdlock)')->first();
        if (! $row) {
            throw new ModelNotFoundException;
        }
        $register = $this->cash->register($row->register_id);

        return new SessionSnapshot($row->id, $register, $row->opened_by, $row->status, $row->opening_fund, Carbon::parse($row->opened_at, 'UTC')->toISOString(), $row->closed_at === null ? null : Carbon::parse($row->closed_at, 'UTC')->toISOString(), $row->closed_by);
    }

    public function balance(string $actorId, ?string $sessionId = null): ?array
    {
        return $this->transactions->run(function () use ($actorId, $sessionId): ?array {
            $actor = $this->authorize($actorId, false);
            $session = $this->selected($actor, $sessionId);

            return $session ? $this->store->balance($session->id, $session->openingFund) : null;
        });
    }

    public function search(string $actorId, array $filters = []): array
    {
        return $this->transactions->run(function () use ($actorId, $filters): array {
            $actor = $this->authorize($actorId, false);
            $session = $this->selected($actor, $filters['cash_session_id'] ?? null);
            $page = $filters['page'] ?? 1;
            $perPage = $filters['per_page'] ?? 25;
            if (! is_int($page) || $page < 1 || $page > 1000000 || ! in_array($perPage, [10, 25, 50, 100], true) || isset($filters['type']) && ! in_array($filters['type'], ['cash_in', 'cash_out'], true)) {
                throw ValidationException::withMessages(['filters' => 'Filtros o paginación inválidos.']);
            }
            if (isset($filters['created_by']) && (! Str::isUlid($filters['created_by']) || strtolower($filters['created_by']) !== $actorId && ! $this->policy->allows($actor, 'cash.administrative.execute'))) {
                throw new AuthorizationException;
            }
            $query = DB::table('cash_movements')->where('cash_session_id', $session->id ?? '');
            foreach (['type', 'created_by'] as $field) {
                if (isset($filters[$field])) {
                    $query->where($field, $filters[$field]);
                }
            }
            if (isset($filters['date'])) {
                $date = $filters['date'];
                if (! is_string($date) || ! preg_match('/\\A[0-9]{4}-[0-9]{2}-[0-9]{2}\\z/', $date) || ! Carbon::canBeCreatedFromFormat($date, 'Y-m-d')) {
                    throw ValidationException::withMessages(['date' => 'Usa una fecha UTC válida YYYY-MM-DD.']);
                }
                $from = Carbon::createFromFormat('!Y-m-d', $date, 'UTC');
                $query->where('occurred_at', '>=', $from)->where('occurred_at', '<', $from->copy()->addDay());
            }
            $total = $query->count();
            $items = $query->orderByDesc('occurred_at')->orderByDesc('id')->forPage($page, $perPage)->get()->map(fn ($row) => $this->store->movement($row))->all();

            return ['items' => $items, 'page' => $page, 'per_page' => $perPage, 'total' => $total, 'balance' => $session ? $this->store->balance($session->id, $session->openingFund) : null];
        });
    }

    public function find(string $actorId, string $id): CashMovementSnapshot
    {
        return $this->transactions->run(function () use ($actorId, $id): CashMovementSnapshot {
            $actor = $this->authorize($actorId, false);
            $active = $this->cash->active();
            $query = DB::table('cash_movements')->where('id', $id);
            if (! $this->policy->allows($actor, 'cash.administrative.execute')) {
                $query->where('cash_session_id', $active->id ?? '');
            }
            $row = $query->first();
            if (! $row) {
                throw new ModelNotFoundException;
            }

            return $this->store->movement($row);
        });
    }
}
