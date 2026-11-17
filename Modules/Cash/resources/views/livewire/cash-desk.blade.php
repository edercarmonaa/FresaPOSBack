<section class="space-y-6" aria-labelledby="cash-title">
    <style>.cash-form input,.cash-form button{min-height:44px}.cash-form input{max-width:100%;width:100%}.cash-form button{padding:12px 16px}main{overflow-wrap:anywhere}@media(max-width:640px){main{padding:16px!important}}</style>
    <header><p class="text-sm font-semibold text-blue-800">FresaPOS · Operación</p><h1 id="cash-title" class="text-3xl font-bold">Caja</h1><p class="mt-2 text-slate-600">Consulta el estado y registra el fondo inicial al comenzar la sesión.</p></header>
    <div role="status" aria-live="polite">@if($status)<p class="rounded border border-green-300 bg-green-50 p-4 text-green-900">{{ $status }}</p>@endif</div>
    <div data-cash-network hidden role="alert" class="rounded border border-red-300 bg-red-50 p-4">No se pudo actualizar. Conservamos el fondo ingresado. Comprueba tu sesión y conexión antes de reintentar. <a class="underline" href="{{ route('login') }}">Iniciar sesión</a></div>
    @if($errors->any())<div role="alert" class="rounded border border-red-300 bg-red-50 p-4"><strong>No se completó la apertura.</strong><ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>@endif
    @if(count($registers) === 0)
        <p class="rounded border bg-white p-4">La caja todavía no ha sido aprovisionada. Contacta al administrador.</p>
    @else
        <article class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3"><h2 class="text-xl font-bold">{{ $registers[0]->name }}</h2><span class="rounded bg-slate-100 px-3 py-2 font-semibold">{{ $session ? 'Abierta' : 'Sin sesión abierta' }}</span></div>
            @if(!$registers[0]->isActive)<p class="mt-3 text-red-800">Caja inactiva. No admite nuevas sesiones.</p>@endif
            @if($session)
                <dl class="mt-5 grid gap-4 sm:grid-cols-2"><div><dt class="text-slate-600">Responsable de apertura</dt><dd class="font-semibold">{{ $responsible ?? $session['opened_by'] }}</dd></div><div><dt class="text-slate-600">Fondo inicial</dt><dd class="text-2xl font-bold">$ {{ $session['opening_fund'] }}</dd></div><div><dt class="text-slate-600">Apertura (UTC)</dt><dd><time datetime="{{ $session['opened_at'] }}">{{ $session['opened_at'] }}</time></dd></div><div><dt class="text-slate-600">Sesión</dt><dd class="break-all font-mono text-sm">{{ $session['id'] }}</dd></div></dl>
                <p class="mt-5 text-slate-600">El fondo inicial es efectivo de apertura; no representa ingresos por ventas. El arqueo y cierre se incorporarán en una etapa posterior.</p>
            @elseif($canOpen && $registers[0]->isActive)
                <form wire:submit="openSession" class="cash-form mt-5 space-y-4" aria-describedby="cash-fund-help">
                    <div><label for="cash-fund" class="block font-semibold">Fondo inicial</label><input id="cash-fund" wire:model="openingFund" type="text" inputmode="decimal" maxlength="20" autocomplete="off" class="mt-2 rounded border border-slate-400 p-3" aria-describedby="cash-fund-help"><p id="cash-fund-help" class="mt-2 text-sm text-slate-600">Puede ser cero. Usa punto decimal y hasta cuatro decimales. El fondo se conserva con la sesión.</p></div>
                    <button type="submit" wire:loading.attr="disabled" class="rounded bg-blue-800 font-semibold text-white disabled:opacity-50">Confirmar apertura de caja</button>
                </form>
            @else
                <p class="mt-4">No tienes permiso para abrir esta caja.</p>
            @endif
        </article>
    @endif
    <div class="flex flex-wrap gap-3"><button type="button" wire:click="refreshState" wire:loading.attr="disabled" class="min-h-11 rounded border border-slate-400 bg-white px-4 py-3">Actualizar estado</button><span wire:loading.delay role="status" class="p-3">Consultando caja…</span></div>
    @script
    <script>
        const root = $wire.$el;
        if (window.cashStopIntercept) window.cashStopIntercept();
        window.cashStopIntercept = Livewire.interceptRequest(({ request, onError, onFailure, onSuccess }) => {
            if (!root.isConnected) return;
            request.options.headers.Accept = 'application/json';
            const notice = $wire.$el.querySelector('[data-cash-network]');
            onError(({ preventDefault }) => { preventDefault(); notice.hidden = false; });
            onFailure(() => { notice.hidden = false; });
            onSuccess(() => { notice.hidden = true; });
        });
    </script>
    @endscript
</section>
