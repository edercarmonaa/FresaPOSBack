<section class="pos-editor pos-spaced sale-controls" aria-label="Operaciones autorizadas de venta">
    <h3>Operaciones con motivo autorizado</h3>
    @if($status)<p role="status" class="pos-success">{{ $status }}</p>@endif
    @if($errors->any())<div role="alert" class="pos-error"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @if($canRemove && $sale->sale->state->value==='open')
        @foreach($sale->items as $item)
            @if($item->removedAt===null)<button type="button" wire:click="prepareRemoval('{{ $item->id }}')" wire:loading.attr="disabled" @disabled($pending)>Retirar {{ $item->name }}</button>@endif
        @endforeach
    @endif
    @if($canCancel && in_array($sale->sale->state->value,['open','paused'],true))<button type="button" wire:click="prepareCancellation" wire:loading.attr="disabled" @disabled($pending)>Cancelar venta no pagada</button>@endif
    @if($formOpen)
        <form wire:submit="confirmOperation" class="pos-spaced" aria-label="Confirmación de operación autorizada">
            <h4>{{ $action==='remove' ? 'Confirmar retiro de producto' : 'Confirmar cancelación de venta no pagada' }}</h4>
            @if($action==='remove')<p>{{ $target['name'] ?? '' }} · {{ $target['quantity'] ?? '' }} unidades · Importe histórico ${{ $target['line_total'] ?? '' }}</p>@else<p>Total vigente ${{ $target['total'] ?? '' }}. Se conservarán las líneas; no se genera reembolso ni movimiento de caja.</p>@endif
            @if(!$submitted && count($reasons)===0)<p role="alert">No hay motivos activos compatibles. Un administrador debe configurarlos antes de permitir esta operación.</p>@endif
            <label class="pos-label" for="sale-action-reason">Motivo autorizado</label>
            <select id="sale-action-reason" wire:model="reasonCode" @disabled($submitted || $pending) style="width:100%;min-height:44px"><option value="">Selecciona un motivo</option>@foreach($reasons as $reason)<option value="{{ $reason['code'] }}">{{ $reason['name'] }}</option>@endforeach</select>
            @if(!$submitted && $reasonTotal>25)<div class="pos-row"><button type="button" wire:click="reasonPage({{ $reasonPage-1 }})" @disabled($reasonPage<=1) wire:loading.attr="disabled">Motivos anteriores</button><span>Página {{ $reasonPage }}</span><button type="button" wire:click="reasonPage({{ $reasonPage+1 }})" @disabled($reasonPage*25 >= $reasonTotal) wire:loading.attr="disabled">Más motivos</button></div>@endif
            @if($submitted)<p>Motivo enviado: {{ $payload['reason_code'] ?? '' }}. El reintento conserva exactamente la misma solicitud.</p>@endif
            <button type="submit" wire:loading.attr="disabled" @disabled($pending || (!$submitted && count($reasons)===0))>Confirmar operación autorizada</button>
        </form>
    @endif
    @if($key!=='')<button type="button" wire:click="queryOperation" wire:loading.attr="disabled">Consultar estado de la operación</button>@endif
    @if($result!==[])<p class="pos-success">Estado de la venta: {{ $result['state'] }} · Total ${{ $result['total'] }}. Operación confirmada sin duplicar efectos.</p>@endif
    <p wire:loading role="status" class="pos-muted">Procesando… Espera la confirmación.</p><p wire:offline role="alert" class="pos-error">Sin conexión. Consulta el estado antes de preparar otra intención.</p>
</section>
