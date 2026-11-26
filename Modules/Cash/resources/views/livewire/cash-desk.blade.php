<section class="ui-cash-module" aria-labelledby="cash-title">
    <header><p class="ui-cash-eyebrow">FresaPOS · Operación</p><h1 id="cash-title" class="ui-heading">Caja</h1><p class="ui-cash-help">Consulta el estado y registra el fondo inicial al comenzar la sesión.</p></header>
    <div role="status" aria-live="polite">@if($status)<p class="ui-cash-status">{{ $status }}</p>@endif</div>
    <div data-cash-network hidden role="alert" class="ui-cash-error">No se pudo actualizar. Conservamos los datos ingresados. Comprueba tu sesión y conexión; consulta el estado de la operación antes de reintentar. <a class="underline" href="{{ route('login') }}">Iniciar sesión</a></div>
    @if($errors->any())<div role="alert" class="ui-cash-error"><strong>No se completó la apertura.</strong><ul>@foreach($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div>@endif
    @if(count($registers) === 0)
        <p class="ui-cash-empty">La caja todavía no ha sido aprovisionada. Contacta al administrador.</p>
    @else
        <article class="ui-cash-card">
            <div class="ui-cash-card-heading"><h2 class="ui-section-title">{{ $registers[0]->name }}</h2><span class="ui-cash-badge">{{ $session ? 'Abierta' : 'Sin sesión abierta' }}</span></div>
            @if(!$registers[0]->isActive)<p class="ui-cash-error-text">Caja inactiva. No admite nuevas sesiones.</p>@endif
            @if($session)
                <dl class="ui-cash-details"><div><dt class="ui-muted">Responsable de apertura</dt><dd class="font-semibold">{{ $responsible ?? $session['opened_by'] }}</dd></div>@if(!$counting)<div><dt class="ui-muted">Fondo inicial</dt><dd class="ui-cash-amount">$ {{ $session['opening_fund'] }}</dd></div>@endif<div><dt class="ui-muted">Apertura (UTC)</dt><dd><time datetime="{{ $session['opened_at'] }}">{{ $session['opened_at'] }}</time></dd></div><div><dt class="ui-muted">Sesión</dt><dd class="ui-cash-reference">{{ $session['id'] }}</dd></div></dl>
                <p class="ui-cash-help">El fondo inicial es efectivo de apertura; no representa ingresos por ventas. El cierre requiere un conteo físico y resuelve la sesión completa.</p>
            @elseif($canOpen && $registers[0]->isActive)
                <form wire:submit="openSession" class="cash-form ui-cash-opening-form" aria-describedby="cash-fund-help">
                    <div><label for="cash-fund" class="block font-semibold">Fondo inicial</label><input id="cash-fund" wire:model="openingFund" type="text" inputmode="decimal" maxlength="20" autocomplete="off" class="ui-input" aria-describedby="cash-fund-help"><p id="cash-fund-help" class="ui-cash-help">Puede ser cero. Usa punto decimal y hasta cuatro decimales. El fondo se conserva con la sesión.</p></div>
                    <button type="submit" wire:loading.attr="disabled" class="ui-button ui-button--primary">Confirmar apertura de caja</button>
                </form>
            @else
                <p class="mt-4">No tienes permiso para abrir esta caja.</p>
            @endif
        </article>
    @endif
    <div class="ui-admin-actions"><button type="button" wire:click="refreshState" wire:loading.attr="disabled" class="ui-button ui-button--secondary">Actualizar estado</button><span wire:loading.delay role="status" class="p-3">Consultando caja…</span></div>
    @if(!$counting)<livewire:cash.movements :key="'cash-movements-'.($session['id'] ?? 'none')" />@endif
    @if($canViewClosings && \Illuminate\Support\Facades\Schema::hasTable('cash_reconciliations'))
        <livewire:cash.closing :key="'cash-closing-'.$actorContext" />
    @endif
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
