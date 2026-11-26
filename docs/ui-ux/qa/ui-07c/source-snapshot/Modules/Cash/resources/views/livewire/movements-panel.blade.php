<section class="cash-movements ui-cash-panel" aria-labelledby="cash-movements-title">
    <header><h2 id="cash-movements-title" class="ui-section-title">Movimientos de efectivo</h2><p class="ui-cash-help">Entradas y salidas manuales autorizadas. El saldo también incluye el efectivo neto de ventas cobradas.</p></header>
    @if($status)<p role="status" aria-live="polite" class="ui-cash-status">{{ $status }}</p>@endif
    @if($errors->any())<div role="alert" class="ui-cash-error"><strong>No se registró el movimiento.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <p wire:loading role="status">Procesando… Espera la confirmación antes de preparar otro movimiento.</p>
    @if($pending)<button type="button" wire:click="resolveMovement" wire:loading.attr="disabled" class="ui-button ui-button--secondary">Consultar operación pendiente</button>
    @elseif($history['balance'])
        <dl class="ui-cash-totals"><div><dt>Entradas manuales</dt><dd class="ui-cash-amount">$ {{ $history['balance']['cash_in'] }}</dd></div><div><dt>Salidas manuales</dt><dd class="ui-cash-amount">$ {{ $history['balance']['cash_out'] }}</dd></div><div><dt>Saldo teórico de efectivo</dt><dd class="ui-cash-amount">$ {{ $history['balance']['expected_cash'] }}</dd></div></dl>
        <p class="ui-cash-help">Efectivo neto confirmado de ventas: <strong>$ {{ $history['balance']['confirmed_sales_cash'] }}</strong>. Fondo inicial contado una sola vez. Las ventas abiertas y los pagos preparados no aportan ingresos.</p>
        @if($canMove)
            <form wire:submit="recordMovement" class="ui-cash-form-grid" aria-label="Registrar movimiento de efectivo">
                <label class="block font-semibold" for="cash-movement-type">Tipo<select id="cash-movement-type" wire:model="movementType" class="ui-input" @disabled($resolved)><option value="cash_in">Entrada de efectivo</option><option value="cash_out">Salida de efectivo</option></select></label>
                <label class="block font-semibold" for="cash-movement-amount">Importe<input id="cash-movement-amount" type="text" inputmode="decimal" autocomplete="off" maxlength="20" wire:model="amount" aria-describedby="cash-movement-help" aria-invalid="{{ $errors->has('amount') ? 'true' : 'false' }}" class="ui-input" @disabled($resolved)></label>
                <label class="block font-semibold sm:col-span-2" for="cash-movement-concept">Concepto obligatorio<textarea id="cash-movement-concept" rows="3" maxlength="200" wire:model="concept" aria-invalid="{{ $errors->has('concept') ? 'true' : 'false' }}" class="ui-input" @disabled($resolved)></textarea></label>
                <p id="cash-movement-help" class="text-sm text-slate-600 sm:col-span-2">Importe positivo, punto decimal y hasta cuatro decimales. El saldo y los permisos se verifican en el servidor.</p>
                <button type="submit" wire:loading.attr="disabled" @disabled($resolved) class="ui-button ui-button--primary">Confirmar movimiento</button>
                <button type="button" wire:click="prepareMovement" wire:loading.attr="disabled" class="ui-button ui-button--secondary">Preparar otro movimiento</button>
            </form>
        @else<p>No tienes permiso para registrar entradas o salidas de efectivo.</p>@endif
    @else<p>Sin sesión abierta: no se permiten movimientos.</p>@endif
    <h3 class="ui-cash-amount">Historial de la sesión actual</h3>
    @if(!$pending)<button type="button" wire:click="resolveMovement" wire:loading.attr="disabled" class="ui-button ui-button--secondary">Actualizar historial y estado</button>@endif
    @if(count($history['items'])===0)<p>No hay movimientos para mostrar.</p>
    @else<table class="cash-movement-table"><thead><tr><th scope="col">Fecha UTC / referencia</th><th scope="col">Tipo / importe</th><th scope="col">Concepto / responsable</th></tr></thead><tbody>@foreach($history['items'] as $movement)<tr wire:key="movement-{{ $movement->id }}"><td data-label="Fecha UTC"><time datetime="{{ $movement->occurredAt }}">{{ $movement->occurredAt }}</time><br><span class="ui-cash-reference">{{ $movement->id }}</span></td><td data-label="Movimiento">{{ $movement->type==='cash_in' ? 'Entrada' : 'Salida' }}<br><strong>$ {{ $movement->amount }}</strong></td><td data-label="Concepto">{{ $movement->concept }}<br><span class="ui-cash-reference">{{ $movement->createdBy }}</span></td></tr>@endforeach</tbody></table>@endif
    @if($history['total']>10)<nav aria-label="Paginación de movimientos" class="ui-admin-actions"><button type="button" wire:click="changePage({{ max(1,$page-1) }})" @disabled($page<=1) class="ui-button ui-button--secondary">Anteriores</button><span>Página {{ $page }}</span><button type="button" wire:click="changePage({{ $page+1 }})" @disabled($page*10 >= $history['total']) class="ui-button ui-button--secondary">Siguientes</button></nav>@endif
</section>
