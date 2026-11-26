@auth('web')
    @can('sales.view')
        @can('catalog.products.view')
            <nav aria-label="Punto de venta" class="pos-navigation mb-4 flex flex-wrap items-center gap-3 border-b border-slate-200 pb-3">
                <a href="{{ route('sales.pos') }}" aria-label="Punto de venta" title="Punto de venta" class="ui-nav-link rounded px-3 py-3 font-semibold text-blue-800 underline focus-visible:outline-2" @if(request()->routeIs('sales.pos')) aria-current="page" @endif><x-ui.icon name="menu" /><span class="ui-nav-label">Punto de venta</span></a>
                <a href="{{ route('auth.account') }}" aria-label="Mi cuenta" title="Mi cuenta" class="ui-nav-link rounded px-3 py-3 text-blue-800 underline"><x-ui.icon name="info" /><span class="ui-nav-label">Mi cuenta</span></a>
                @can('users.view')<a href="{{ route('auth.admin') }}" aria-label="Administración" title="Administración" class="ui-nav-link rounded px-3 py-3 text-blue-800 underline"><x-ui.icon name="menu" /><span class="ui-nav-label">Administración</span></a>@endcan
            </nav>
        @endcan
    @endcan
@endauth
