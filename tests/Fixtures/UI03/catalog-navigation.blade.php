@auth('web')
    @if(auth('web')->user()->can('catalog.categories.manage') || (auth('web')->user()->can('catalog.products.view') && auth('web')->user()->canAny(['catalog.products.create', 'catalog.products.update', 'catalog.products.deactivate', 'catalog.products.restore'])))
        <nav aria-label="Catálogo" class="mb-6 flex flex-wrap items-center gap-4 border-b border-slate-200 pb-4">
            <span class="font-semibold">Catálogo</span>
            @can('catalog.products.view')
                @canany(['catalog.products.create', 'catalog.products.update', 'catalog.products.deactivate', 'catalog.products.restore'])
                    <a href="{{ route('catalog.products') }}" class="rounded px-2 py-2 text-blue-700 underline" @if(request()->routeIs('catalog.products')) aria-current="page" @endif>Productos</a>
                @endcanany
            @endcan
            @can('catalog.categories.manage')
                <a href="{{ route('catalog.categories') }}" class="rounded px-2 py-2 text-blue-700 underline" @if(request()->routeIs('catalog.categories')) aria-current="page" @endif>Categorías</a>
            @endcan
            <a href="{{ route('auth.account') }}" class="ml-auto px-2 py-2 text-blue-700 underline">Mi cuenta</a>
        </nav>
    @endif
@endauth
