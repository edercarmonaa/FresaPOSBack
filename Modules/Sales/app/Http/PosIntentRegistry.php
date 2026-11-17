<?php

namespace Modules\Sales\Http;

use Illuminate\Support\Str;
use Modules\Core\Contracts\ApiCode;
use Modules\Core\Contracts\IdempotentOperations;
use Modules\Core\Contracts\OperationIntent;
use Modules\Core\Contracts\OperationRejected;
use Modules\Core\Contracts\OperationStatus;

/** Solo continuidad de intención/interfaz; SQL Core sigue siendo la fuente de verdad. */
final class PosIntentRegistry
{
    private function path(string $actor): string
    {
        return 'sales_pos_intents.'.OperationIntent::context($actor);
    }

    public function key(string $actor, string $slot, bool $new = false): string
    {
        $path = $this->path($actor).'.keys.'.$slot;
        $key = session()->get($path);
        if ($new) {
            $this->resolved($actor);
        }
        if ($new || ! is_string($key)) {
            $key = (string) Str::uuid();
            session()->put($path, $key);
        }

        return $key;
    }

    public function resolved(string $actor): void
    {
        if ($this->last($actor)->state === 'pending') {
            throw new OperationRejected(ApiCode::OPERATION_IN_PROGRESS);
        }
    }

    public function begin(string $actor, string $key, string $scope, ?string $saleId): void
    {
        $path = $this->path($actor);
        $previous = session()->get($path.'.last_key');
        if ($previous !== $key) {
            $this->resolved($actor);
        }
        session()->put($path.'.last_key', OperationIntent::key($key));
        session()->put($path.'.last_scope', $scope);
        if ($saleId !== null) {
            $this->sale($actor, $saleId);
        }
        // Se guarda antes del negocio: un corte tras commit conserva la clave a consultar.
        session()->save();
    }

    public function last(string $actor): OperationStatus
    {
        $key = session()->get($this->path($actor).'.last_key');

        return is_string($key) ? app(IdempotentOperations::class)->status(OperationIntent::context($actor), $key) : new OperationStatus('not_found');
    }

    public function selection(string $actor): array
    {
        return array_replace(['mode' => 'counter', 'table_id' => null, 'sale_id' => null], session()->get($this->path($actor).'.selection', []));
    }

    public function target(string $actor, string $mode, ?string $tableId): void
    {
        // Preparación explícita tras resolved(): la intención anterior ya fue reconocida.
        session()->forget([$this->path($actor).'.last_key', $this->path($actor).'.last_scope']);
        session()->put($this->path($actor).'.selection', ['mode' => $mode, 'table_id' => $tableId, 'sale_id' => null]);
    }

    public function sale(string $actor, string $saleId): void
    {
        session()->put($this->path($actor).'.selection.sale_id', $saleId);
    }
}
