@auth('web')
    @can('sales.view')
        @can('catalog.products.view')
            <nav aria-label="Punto de venta" class="pos-navigation mb-4 flex flex-wrap items-center gap-3 border-b border-slate-200 pb-3">
                <a href="{{ route('sales.pos') }}" class="rounded px-3 py-3 font-semibold text-blue-800 underline focus-visible:outline-2" @if(request()->routeIs('sales.pos')) aria-current="page" @endif>Punto de venta</a>
                <a href="{{ route('auth.account') }}" class="rounded px-3 py-3 text-blue-800 underline">Mi cuenta</a>
                @can('users.view')<a href="{{ route('auth.admin') }}" class="rounded px-3 py-3 text-blue-800 underline">Administración</a>@endcan
            </nav>
        @endcan
    @endcan
@endauth
