@auth('web')
    @if(auth('web')->user()->can('catalog.categories.manage') || (auth('web')->user()->can('catalog.products.view') && auth('web')->user()->canAny(['catalog.products.create', 'catalog.products.update', 'catalog.products.deactivate', 'catalog.products.restore'])))
        <nav aria-label="Catálogo" class="mb-6 flex flex-wrap items-center gap-4 border-b border-slate-200 pb-4">
            <span class="ui-nav-heading">Catálogo</span>
            @can('catalog.products.view')
                @canany(['catalog.products.create', 'catalog.products.update', 'catalog.products.deactivate', 'catalog.products.restore'])
                    <a href="{{ route('catalog.products') }}" aria-label="Productos" title="Productos" class="ui-nav-link rounded px-2 py-2 text-blue-700 underline" @if(request()->routeIs('catalog.products')) aria-current="page" @endif><x-ui.icon name="menu" /><span class="ui-nav-label">Productos</span></a>
                @endcanany
            @endcan
            @can('catalog.categories.manage')
                <a href="{{ route('catalog.categories') }}" aria-label="Categorías" title="Categorías" class="ui-nav-link rounded px-2 py-2 text-blue-700 underline" @if(request()->routeIs('catalog.categories')) aria-current="page" @endif><x-ui.icon name="menu" /><span class="ui-nav-label">Categorías</span></a>
            @endcan
            <a href="{{ route('auth.account') }}" aria-label="Mi cuenta" title="Mi cuenta" class="ui-nav-link ml-auto px-2 py-2 text-blue-700 underline"><x-ui.icon name="info" /><span class="ui-nav-label">Mi cuenta</span></a>
        </nav>
    @endif
@endauth
