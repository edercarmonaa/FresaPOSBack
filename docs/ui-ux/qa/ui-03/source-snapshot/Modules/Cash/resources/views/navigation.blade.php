@auth('web')
    @can('cash.view')
        <nav aria-label="Caja" class="mb-4 border-b border-slate-200 pb-3">
            <a href="{{ route('cash.desk') }}" aria-label="Caja" title="Caja" class="ui-nav-link inline-flex min-h-11 items-center rounded px-3 py-3 font-semibold text-blue-800 underline" @if(request()->routeIs('cash.desk')) aria-current="page" @endif><x-ui.icon name="plus" /><span class="ui-nav-label">Caja</span></a>
        </nav>
    @endcan
@endauth
