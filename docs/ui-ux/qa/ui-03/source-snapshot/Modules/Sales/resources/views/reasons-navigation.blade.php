@auth('web')
    @can('settings.update')
        @can('sales.view')
            <nav aria-label="Administración de ventas" class="mb-6 flex flex-wrap gap-4 border-b pb-4"><a class="ui-nav-link rounded px-3 py-3 text-blue-700 underline" href="{{ route('sales.reasons') }}" aria-label="Motivos autorizados de ventas" title="Motivos autorizados de ventas" @if(request()->routeIs('sales.reasons')) aria-current="page" @endif><x-ui.icon name="warning" /><span class="ui-nav-label">Motivos autorizados de ventas</span></a></nav>
        @endcan
    @endcan
@endauth
