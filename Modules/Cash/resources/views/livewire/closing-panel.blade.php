<section class="cash-closing space-y-5 rounded-xl border border-slate-300 bg-white p-5" aria-labelledby="cash-closing-title">
    <style>.cash-closing input,.cash-closing select,.cash-closing textarea,.cash-closing button,.cash-closing a{min-height:44px}.cash-closing input,.cash-closing select,.cash-closing textarea{max-width:100%;width:100%;border:1px solid #94a3b8;border-radius:6px;padding:10px}.cash-closing button,.cash-closing a{display:inline-flex;align-items:center;padding:10px 16px;border-radius:6px}.cash-closing dl>div{min-width:0}.cash-closing dd{overflow-wrap:anywhere}.cash-closing button:disabled{opacity:.55}</style>
    <header><h2 id="cash-closing-title" class="text-2xl font-bold">Arqueo y cierre de caja</h2><p class="mt-2 text-slate-600">Cuenta el efectivo físico antes de confirmar. El resultado se mostrará cuando la sesión quede cerrada.</p></header>
    @if($errors->any())<div role="alert" class="rounded border border-red-300 bg-red-50 p-4"><strong>No se completó la operación.</strong><ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>@endif
    <div class="flex flex-wrap gap-3">
        @if($canClose)<button type="button" wire:click="beginClosing" wire:loading.attr="disabled" @disabled($pending) class="bg-blue-800 font-semibold text-white">Iniciar arqueo ciego</button>@endif
        @if($key !== '')<button type="button" wire:click="queryClosing" wire:loading.attr="disabled" class="border border-slate-400">Consultar estado del cierre</button>@endif
        <span wire:loading role="status" class="p-3">Procesando; conserva esta intención…</span>
        <span wire:offline role="alert" class="p-3 text-red-800">Sin conexión. Conserva el conteo y consulta el estado antes de reintentar.</span>
    </div>
    @if($pending)<p role="status" class="rounded bg-amber-50 p-4">El cierre sigue en proceso. Consulta el estado; no prepares otra clave.</p>@endif
    @if($pendingTotal > 0)
        <div role="alert" class="rounded border border-amber-300 bg-amber-50 p-4"><strong>Cierre bloqueado: {{ $pendingTotal }} ventas pendientes.</strong><p>Resuélvelas mediante las operaciones autorizadas del POS. No se cancelan ni trasladan automáticamente.</p><ul class="mt-3">@foreach($pendingSales as $sale)<li class="break-all font-mono text-sm">{{ $sale['id'] }} · {{ $sale['type'] }} · {{ $sale['status'] }}@if($sale['table_id']) · Mesa {{ $sale['table_id'] }}@endif</li>@endforeach</ul>@if($pendingTotal > count($pendingSales))<p>Se muestran las primeras 25; el POS permite consultar las demás.</p>@endif<a href="{{ route('sales.pos') }}" class="mt-3 underline">Regresar al POS</a></div>
    @endif
    @if($formOpen && $receipt === [])
        <form wire:submit="confirmClosing" class="space-y-4" aria-describedby="blind-count-help">
            <p id="blind-count-help" class="rounded bg-blue-50 p-4">Introduce únicamente lo contado. Al confirmar se guardarán el arqueo y el cierre definitivo. La sesión no podrá reabrirse.</p>
            <p class="break-all text-sm">Sesión: {{ $cashSessionId }}</p>
            <div><label for="closing-counted" class="block font-semibold">Efectivo contado</label><input id="closing-counted" wire:model="countedCash" type="text" inputmode="decimal" maxlength="20" autocomplete="off" required aria-describedby="closing-precision"><p id="closing-precision" class="text-sm text-slate-600">Puede ser cero. Usa punto y hasta cuatro decimales.</p></div>
            <div><label for="closing-observation" class="block font-semibold">Observación del conteo (obligatoria)</label><textarea id="closing-observation" wire:model="observation" maxlength="200" rows="3" required></textarea></div>
            <button type="submit" wire:loading.attr="disabled" @disabled($pending) class="bg-blue-800 font-bold text-white">Confirmar conteo y cerrar caja</button>
        </form>
    @endif
    @if($receipt !== [])
        <article data-closing-receipt class="space-y-4 rounded border border-green-400 bg-green-50 p-4" aria-labelledby="closing-result-title">
            <h3 id="closing-result-title" class="text-xl font-bold">Caja cerrada · {{ ['balanced'=>'Caja cuadrada','shortage'=>'Faltante','surplus'=>'Sobrante'][$receipt['outcome']] }}</h3>
            <dl class="grid gap-4 sm:grid-cols-2">
                @foreach(['register_name'=>'Caja','cash_session_id'=>'Sesión','id'=>'Arqueo','opened_by'=>'Usuario de apertura','opened_at'=>'Apertura UTC','closed_by'=>'Usuario de cierre','closed_at'=>'Cierre UTC','opening_fund'=>'Fondo inicial','closed_sales'=>'Ventas cobradas','total_sold'=>'Total vendido','confirmed_sales_cash'=>'Efectivo neto de ventas','cash_in'=>'Entradas autorizadas','cash_out'=>'Salidas autorizadas','expected_cash'=>'Efectivo esperado','counted_cash'=>'Efectivo contado','difference'=>'Diferencia','observation'=>'Observación'] as $field=>$label)
                    <div><dt class="text-sm text-slate-600">{{ $label }}</dt><dd class="font-semibold">{{ $receipt[$field] }}</dd></div>
                @endforeach
            </dl>
            <h4 class="font-bold">Pagos confirmados por método</h4><ul>@forelse($receipt['payment_methods'] as $method)<li>{{ ['cash'=>'Efectivo','card'=>'Tarjeta','transfer'=>'Transferencia','other'=>'Otro'][$method['method_code']] }}: $ {{ $method['applied_amount'] }} · {{ $method['payment_count'] }} pagos</li>@empty<li>Sin pagos de venta en esta sesión.</li>@endforelse</ul>
            <p>No se generan ajustes para compensar diferencias. Para otro turno, abre expresamente una nueva sesión y registra su fondo inicial.</p>
        </article>
    @endif
    <div class="space-y-4 border-t border-slate-200 pt-5">
        <h3 class="text-xl font-bold">Historial de cierres</h3>
        <form wire:submit="filterHistory" class="grid gap-3 sm:grid-cols-2">
            <div><label for="closing-date">Fecha de cierre UTC</label><input id="closing-date" type="date" wire:model="date"></div>
            <div><label for="closing-user">Usuario de cierre (ULID)</label><input id="closing-user" type="text" maxlength="26" wire:model="closedBy"></div>
            <div><label for="closing-register">Caja (ULID)</label><input id="closing-register" type="text" maxlength="26" wire:model="registerId"></div>
            <div><label for="closing-state">Estado</label><select id="closing-state" wire:model="historyStatus"><option value="closed">Cerradas</option><option value="open">Abiertas (sin cierre histórico)</option></select></div>
            <button type="submit" wire:loading.attr="disabled" class="border border-slate-400">Filtrar historial</button>
        </form>
        <p>{{ $history['total'] }} cierres · Página {{ $history['page'] }}</p>
        <div class="grid gap-3 lg:grid-cols-2">@forelse($history['items'] as $item)
            <article class="min-w-0 rounded border border-slate-200 p-4"><h4 class="font-semibold">{{ $item['register_name'] }} · {{ $item['closed_at'] }}</h4><p class="break-all text-sm">Sesión {{ $item['cash_session_id'] }}</p><p>Esperado $ {{ $item['expected_cash'] }} · Contado $ {{ $item['counted_cash'] }} · Diferencia $ {{ $item['difference'] }}</p><button type="button" wire:click="showClosing('{{ $item['cash_session_id'] }}')" wire:loading.attr="disabled" class="mt-3 border border-slate-400">Ver resumen del cierre</button></article>
        @empty<p>No hay cierres para estos filtros.</p>@endforelse</div>
        <nav aria-label="Páginas de cierres" class="flex flex-wrap gap-3"><button type="button" wire:click="changePage({{ max(1,$page-1) }})" @disabled($page<=1) wire:loading.attr="disabled" class="border border-slate-400">Anterior</button><button type="button" wire:click="changePage({{ $page+1 }})" @disabled($page*10 >= $history['total']) wire:loading.attr="disabled" class="border border-slate-400">Siguiente</button></nav>
    </div>
</section>
