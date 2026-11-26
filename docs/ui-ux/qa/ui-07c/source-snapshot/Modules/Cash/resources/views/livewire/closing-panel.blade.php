<section class="cash-closing ui-cash-panel" aria-labelledby="cash-closing-title">
    <header><h2 id="cash-closing-title" class="ui-section-title">Arqueo y cierre de caja</h2><p class="ui-cash-help">Cuenta el efectivo físico antes de confirmar. El resultado se mostrará cuando la sesión quede cerrada.</p></header>
    @if($errors->any())<div role="alert" class="ui-cash-error"><strong>No se completó la operación.</strong><ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>@endif
    <div class="ui-admin-actions">
        @if($canClose)<button type="button" wire:click="beginClosing" wire:loading.attr="disabled" @disabled($pending) class="ui-button ui-button--primary">Iniciar arqueo ciego</button>@endif
        @if($key !== '')<button type="button" wire:click="queryClosing" wire:loading.attr="disabled" class="ui-button ui-button--secondary">Consultar estado del cierre</button>@endif
        <span wire:loading role="status" class="p-3">Procesando; conserva esta intención…</span>
        <span wire:offline role="alert" class="ui-cash-offline">Sin conexión. Conserva el conteo y consulta el estado antes de reintentar.</span>
    </div>
    @if($pending)<p role="status" class="ui-cash-warning">El cierre sigue en proceso. Consulta el estado; no prepares otra clave.</p>@endif
    @if($pendingTotal > 0)
        <div role="alert" class="ui-cash-warning"><strong>Cierre bloqueado: {{ $pendingTotal }} ventas pendientes.</strong><p>Resuelve las ventas abiertas, pausadas o en corrección mediante las operaciones autorizadas del POS. No se cancelan ni trasladan automáticamente.</p><ul class="mt-3">@foreach($pendingSales as $sale)<li class="ui-cash-reference"><a href="{{ route('sales.pos',['sale'=>$sale['id']]) }}">{{ $sale['id'] }}</a> · {{ $sale['type'] }} · {{ ['open'=>'Abierta','paused'=>'Pausada','correcting'=>'En corrección'][$sale['status']] ?? $sale['status'] }}@if($sale['table_id']) · Mesa {{ $sale['table_id'] }}@endif</li>@endforeach</ul>@if($pendingTotal > count($pendingSales))<p>Se muestran las primeras 25; el POS permite consultar las demás.</p>@endif<a href="{{ route('sales.pos') }}" class="mt-3 underline">Regresar al POS</a></div>
    @endif
    @if($formOpen && $receipt === [])
        <form wire:submit="confirmClosing" class="space-y-4" aria-describedby="blind-count-help">
            <p id="blind-count-help" class="ui-cash-information">Introduce únicamente lo contado. Al confirmar se guardarán el arqueo y el cierre definitivo. La sesión no podrá reabrirse.</p>
            <p class="ui-cash-reference">Sesión: {{ $cashSessionId }}</p>
            <div><label for="closing-counted" class="block font-semibold">Efectivo contado</label><input id="closing-counted" wire:model="countedCash" type="text" inputmode="decimal" maxlength="20" autocomplete="off" required aria-describedby="closing-precision"><p id="closing-precision" class="ui-cash-help">Puede ser cero. Usa punto y hasta cuatro decimales.</p></div>
            <div><label for="closing-observation" class="block font-semibold">Observación del conteo (obligatoria)</label><textarea id="closing-observation" wire:model="observation" maxlength="200" rows="3" required></textarea></div>
            <button type="submit" wire:loading.attr="disabled" @disabled($pending) class="ui-button ui-button--primary">Confirmar conteo y cerrar caja</button>
        </form>
    @endif
    @if($receipt !== [])
        <article data-closing-receipt class="ui-cash-receipt" aria-labelledby="closing-result-title">
            <h3 id="closing-result-title" class="ui-section-title">Caja cerrada · {{ ['balanced'=>'Caja cuadrada','shortage'=>'Faltante','surplus'=>'Sobrante'][$receipt['outcome']] }}</h3>
            <dl class="ui-cash-details">
                @foreach(['register_name'=>'Caja','cash_session_id'=>'Sesión','id'=>'Arqueo','opened_by'=>'Usuario de apertura','opened_at'=>'Apertura UTC','closed_by'=>'Usuario de cierre','closed_at'=>'Cierre UTC','opening_fund'=>'Fondo inicial','closed_sales'=>'Ventas cobradas','total_sold'=>'Total vendido','confirmed_sales_cash'=>'Efectivo neto de ventas','cash_in'=>'Entradas autorizadas','cash_out'=>'Salidas autorizadas','expected_cash'=>'Efectivo esperado','counted_cash'=>'Efectivo contado','difference'=>'Diferencia','observation'=>'Observación'] as $field=>$label)
                    <div><dt class="ui-cash-help">{{ $label }}</dt><dd class="font-semibold">{{ $receipt[$field] }}</dd></div>
                @endforeach
            </dl>
            <h4 class="font-bold">Pagos confirmados por método</h4><ul>@forelse($receipt['payment_methods'] as $method)<li>{{ ['cash'=>'Efectivo','card'=>'Tarjeta','transfer'=>'Transferencia','other'=>'Otro'][$method['method_code']] }}: $ {{ $method['applied_amount'] }} · {{ $method['payment_count'] }} pagos</li>@empty<li>Sin pagos de venta en esta sesión.</li>@endforelse</ul>
            <p>No se generan ajustes para compensar diferencias. Para otro turno, abre expresamente una nueva sesión y registra su fondo inicial.</p>
        </article>
    @endif
    <div class="ui-cash-history">
        <h3 class="ui-section-title">Historial de cierres</h3>
        <form wire:submit="filterHistory" class="ui-cash-filter-grid">
            <div><label for="closing-date">Fecha de cierre UTC</label><input id="closing-date" type="date" wire:model="date"></div>
            <div><label for="closing-user">Usuario de cierre (ULID)</label><input id="closing-user" type="text" maxlength="26" wire:model="closedBy"></div>
            <div><label for="closing-register">Caja (ULID)</label><input id="closing-register" type="text" maxlength="26" wire:model="registerId"></div>
            <div><label for="closing-state">Estado</label><select id="closing-state" wire:model="historyStatus"><option value="closed">Cerradas</option><option value="open">Abiertas (sin cierre histórico)</option></select></div>
            <button type="submit" wire:loading.attr="disabled" class="ui-button ui-button--secondary">Filtrar historial</button>
        </form>
        <p>{{ $history['total'] }} cierres · Página {{ $history['page'] }}</p>
        <div class="ui-cash-history-grid">@forelse($history['items'] as $item)
            <article class="ui-cash-history-card"><h4 class="font-semibold">{{ $item['register_name'] }} · {{ $item['closed_at'] }}</h4><p class="ui-cash-reference">Sesión {{ $item['cash_session_id'] }}</p><p>Esperado $ {{ $item['expected_cash'] }} · Contado $ {{ $item['counted_cash'] }} · Diferencia $ {{ $item['difference'] }}</p><button type="button" wire:click="showClosing('{{ $item['cash_session_id'] }}')" wire:loading.attr="disabled" class="ui-button ui-button--secondary">Ver resumen del cierre</button></article>
        @empty<p>No hay cierres para estos filtros.</p>@endforelse</div>
        <nav aria-label="Páginas de cierres" class="ui-admin-actions"><button type="button" wire:click="changePage({{ max(1,$page-1) }})" @disabled($page<=1) wire:loading.attr="disabled" class="ui-button ui-button--secondary">Anterior</button><button type="button" wire:click="changePage({{ $page+1 }})" @disabled($page*10 >= $history['total']) wire:loading.attr="disabled" class="ui-button ui-button--secondary">Siguiente</button></nav>
    </div>
</section>
