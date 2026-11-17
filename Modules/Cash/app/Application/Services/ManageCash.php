<?php

namespace Modules\Cash\Application\Services;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Cash\Application\Ports\CashStore;
use Modules\Cash\Contracts\ActiveCashSession;
use Modules\Cash\Contracts\CashConflict;
use Modules\Cash\Contracts\CashOperations;
use Modules\Cash\Contracts\CashSessionUnavailable;
use Modules\Cash\Contracts\RegisterSnapshot;
use Modules\Cash\Contracts\SessionSnapshot;
use Modules\Cash\Domain\OpeningFund;
use Modules\Cash\Policies\CashPolicy;
use Modules\Core\Contracts\SecurityEvent;
use Modules\Core\Contracts\TransactionManager;
use Modules\Users\Contracts\IdentityAccess;

final class ManageCash implements ActiveCashSession, CashOperations
{
    public function __construct(private readonly TransactionManager $transactions, private readonly IdentityAccess $identities,
        private readonly CashPolicy $policy, private readonly CashStore $store) {}

    public function registers(string $actorId): array
    {
        return $this->authorized($actorId, 'cash.view', fn () => $this->store->registers());
    }

    public function current(string $actorId): ?SessionSnapshot
    {
        return $this->authorized($actorId, 'cash.view', fn () => $this->store->active());
    }

    public function open(string $actorId, string $registerId, mixed $openingFund, string $key): SessionSnapshot
    {
        try {
            return $this->authorized($actorId, 'cash.open', function () use ($actorId, $registerId, $openingFund, $key): SessionSnapshot {
                if (! Str::isUlid($registerId)) {
                    throw ValidationException::withMessages(['register_id' => 'Identificador de caja inválido.']);
                }
                if (preg_match('/\A[A-Za-z0-9_-]{16,128}\z/', $key) !== 1) {
                    throw ValidationException::withMessages(['idempotency_key' => 'Usa una clave de 16 a 128 caracteres.']);
                }
                $fund = OpeningFund::normalize($openingFund);
                $registerId = strtolower($registerId);
                $register = $this->store->register($registerId);
                $fingerprint = hash('sha256', json_encode([$registerId, $fund], JSON_THROW_ON_ERROR));
                $keyHash = hash('sha256', $key);
                $replay = $this->store->replay($actorId, $keyHash, $fingerprint);
                if ($replay !== null) {
                    return $replay; // Nunca vuelve a abrir, incluso si en el futuro la sesión se cierra.
                }
                if (! $register->isActive || $this->store->active() !== null) {
                    throw new CashConflict('La caja está inactiva o ya tiene una sesión abierta.');
                }
                $session = $this->store->create($actorId, $registerId, $fund, $keyHash, $fingerprint);
                event(new SecurityEvent('cash.session.opened', actorId: $actorId, recordId: $session->id));

                return $session;
            });
        } catch (QueryException $exception) {
            if (in_array((int) ($exception->errorInfo[1] ?? 0), [2601, 2627], true)) {
                throw new CashConflict('Apertura en conflicto; consulta la sesión actual.');
            }
            throw $exception;
        } catch (CashConflict $exception) {
            // Fuera de la transacción revertida: intento diagnosticable, sin fondo ni clave.
            event(new SecurityEvent('cash.open.rejected', actorId: $actorId, recordId: Str::isUlid($registerId) ? $registerId : null, result: 'blocked'));
            throw $exception;
        }
    }

    public function setRegisterActive(string $actorId, string $registerId, bool $active): RegisterSnapshot
    {
        return $this->authorized($actorId, 'cash.administrative.execute', function () use ($actorId, $registerId, $active): RegisterSnapshot {
            $register = $this->store->register($registerId);
            if (! $active && $this->store->active() !== null) {
                throw new CashConflict('No se puede desactivar una caja con sesión abierta.');
            }
            if ($register->isActive === $active) {
                return $register;
            }
            $register = $this->store->setActive($registerId, $active);
            event(new SecurityEvent('cash.register.updated', actorId: $actorId, recordId: $register->id));

            return $register;
        });
    }

    public function requireActive(string $actorId, ?string $expectedSessionId = null): string
    {
        // No abre una transacción propia: los locks permanecen en la transacción Sales.
        $this->identities->lock();
        $actor = $this->identities->inspect($actorId);
        if (! $actor || ! $actor->isActive || $actor->mustChangePassword) {
            throw new AuthorizationException;
        }
        $session = $this->store->active();
        if (! $session || ! $session->register->isActive || ($expectedSessionId !== null && $session->id !== strtolower($expectedSessionId))) {
            throw new CashSessionUnavailable('No hay una sesión de caja válida para esta operación.');
        }

        return $session->id;
    }

    private function authorized(string $actorId, string $permission, Closure $operation): mixed
    {
        return $this->transactions->run(function () use ($actorId, $permission, $operation): mixed {
            $this->identities->lock();
            $actor = $this->identities->inspect($actorId);
            if (! $actor || ! $this->policy->allows($actor, $permission)) {
                throw new AuthorizationException;
            }

            return $operation();
        });
    }
}
