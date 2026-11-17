<?php

namespace Modules\Cash\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;
use Modules\Cash\Application\Ports\CashStore;
use Modules\Cash\Contracts\CashConflict;
use Modules\Cash\Contracts\RegisterSnapshot;
use Modules\Cash\Contracts\SessionSnapshot;
use Modules\Cash\Infrastructure\Models\CashRegister;
use Modules\Cash\Infrastructure\Models\CashSession;

final class EloquentCashStore implements CashStore
{
    private function transaction(): void
    {
        if (DB::connection()->transactionLevel() < 1) {
            throw new \LogicException('Cash requiere una transacción Core externa.');
        }
    }

    public function registers(): array
    {
        $this->transaction();

        return CashRegister::query()->orderBy('id')->get()->map(fn ($row) => $this->registerSnapshot($row))->all();
    }

    public function register(string $id): RegisterSnapshot
    {
        $this->transaction();

        return $this->registerSnapshot(CashRegister::query()->lock('with (updlock, holdlock)')->findOrFail($id));
    }

    public function active(): ?SessionSnapshot
    {
        $this->transaction();
        // Orden invariable: identidad -> caja -> sesión. Incluye el hueco sin sesión.
        CashRegister::query()->where('slot', 1)->lock('with (updlock, holdlock)')->first();
        $row = CashSession::query()->with('register')->where('status', 'open')->lock('with (updlock, holdlock)')->first();

        return $row ? $this->snapshot($row) : null;
    }

    public function replay(string $actorId, string $keyHash, string $fingerprint): ?SessionSnapshot
    {
        $this->transaction();
        $row = CashSession::query()->with('register')->where('opened_by', $actorId)->where('opening_key_hash', $keyHash)->lock('with (updlock, holdlock)')->first();
        if ($row && ! hash_equals($row->opening_fingerprint, $fingerprint)) {
            throw new CashConflict('La clave ya fue utilizada con otros datos.');
        }

        return $row ? $this->snapshot($row) : null;
    }

    public function create(string $actorId, string $registerId, string $fund, string $keyHash, string $fingerprint): SessionSnapshot
    {
        $this->transaction();
        $row = CashSession::query()->create(['register_id' => $registerId, 'opened_by' => $actorId, 'status' => 'open',
            'opening_fund' => $fund, 'opened_at' => now()->utc(), 'opening_key_hash' => $keyHash, 'opening_fingerprint' => $fingerprint]);

        // SQL Server datetime redondea fracciones: primera respuesta y replay deben coincidir.
        return $this->snapshot($row->refresh()->load('register'));
    }

    public function setActive(string $id, bool $active): RegisterSnapshot
    {
        $this->transaction();
        $row = CashRegister::query()->findOrFail($id);
        $row->is_active = $active;
        $row->save();

        return $this->registerSnapshot($row);
    }

    public function provision(): RegisterSnapshot
    {
        $this->transaction();
        // El lock de identidad protege también el primer aprovisionamiento sin filas.
        $row = CashRegister::query()->where('slot', 1)->lock('with (updlock, holdlock)')->first();
        $row ??= CashRegister::query()->create(['slot' => 1, 'name' => 'Caja principal', 'is_active' => true]);

        return $this->registerSnapshot($row);
    }

    private function registerSnapshot(CashRegister $row): RegisterSnapshot
    {
        return new RegisterSnapshot($row->id, $row->name, $row->is_active);
    }

    private function snapshot(CashSession $row): SessionSnapshot
    {
        return new SessionSnapshot($row->id, $this->registerSnapshot($row->register), $row->opened_by, $row->status,
            $row->opening_fund, $row->opened_at->utc()->toISOString(), $row->closed_at?->utc()->toISOString(), $row->closed_by);
    }
}
