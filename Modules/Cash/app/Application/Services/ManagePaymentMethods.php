<?php

namespace Modules\Cash\Application\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Cash\Contracts\PaymentMethods;
use Modules\Cash\Contracts\PaymentMethodSnapshot;
use Modules\Cash\Infrastructure\Persistence\FinancialStore;
use Modules\Cash\Policies\CashPolicy;
use Modules\Core\Contracts\SecurityEvent;
use Modules\Core\Contracts\TransactionManager;
use Modules\Users\Contracts\IdentityAccess;

final class ManagePaymentMethods implements PaymentMethods
{
    public function __construct(private readonly TransactionManager $transactions, private readonly IdentityAccess $identities, private readonly CashPolicy $policy, private readonly FinancialStore $store) {}

    public function provision(): array
    {
        return $this->transactions->run(function (): array {
            $this->identities->lock();
            foreach (['cash' => 'Efectivo', 'card' => 'Tarjeta', 'transfer' => 'Transferencia', 'other' => 'Otro método autorizado'] as $code => $name) {
                if (! DB::table('cash_payment_methods')->where('code', $code)->exists()) {
                    DB::table('cash_payment_methods')->insert(['id' => strtolower((string) Str::ulid()), 'code' => $code, 'name' => $name, 'is_active' => true, 'display_order' => array_search($code, ['cash', 'card', 'transfer', 'other'], true), 'created_at' => now()->utc(), 'updated_at' => now()->utc()]);
                }
            }

            return DB::table('cash_payment_methods')->orderBy('display_order')->get()->map(fn ($row) => $this->store->method($row))->all();
        });
    }

    private function authorize(string $actorId, string $permission): void
    {
        $this->identities->lock();
        $actor = $this->identities->inspect($actorId);
        if (! $actor || ! $this->policy->allows($actor, 'cash.view') || ! $this->policy->allows($actor, $permission)) {
            throw new AuthorizationException;
        }
    }

    public function available(string $actorId): array
    {
        return $this->transactions->run(function () use ($actorId): array {
            $this->authorize($actorId, 'cash.view');

            return DB::table('cash_payment_methods')->where('is_active', true)->orderBy('display_order')->orderBy('id')->get()->map(fn ($row) => $this->store->method($row))->all();
        });
    }

    public function find(string $actorId, string $id): PaymentMethodSnapshot
    {
        return $this->transactions->run(function () use ($actorId, $id): PaymentMethodSnapshot {
            $this->authorize($actorId, 'cash.view');
            $row = DB::table('cash_payment_methods')->where('id', $id)->where('is_active', true)->first();
            if (! $row) {
                throw new ModelNotFoundException;
            }

            return $this->store->method($row);
        });
    }

    public function setActive(string $actorId, string $id, bool $active): PaymentMethodSnapshot
    {
        return $this->transactions->run(function () use ($actorId, $id, $active): PaymentMethodSnapshot {
            $this->authorize($actorId, 'cash.administrative.execute');
            $row = DB::table('cash_payment_methods')->where('id', $id)->lock('with (updlock,holdlock)')->first();
            if (! $row) {
                throw new ModelNotFoundException;
            }
            if ((bool) $row->is_active !== $active) {
                DB::table('cash_payment_methods')->where('id', $id)->update(['is_active' => $active, 'updated_at' => now()->utc()]);
                event(new SecurityEvent('cash.payment_method.updated', actorId: $actorId, recordId: $id));
            }

            return $this->store->method(DB::table('cash_payment_methods')->where('id', $id)->first());
        });
    }
}
