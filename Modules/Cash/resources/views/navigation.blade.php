@auth('web')
    @can('cash.view')
        <nav aria-label="Caja" class="mb-4 border-b border-slate-200 pb-3">
            <a href="{{ route('cash.desk') }}" class="inline-flex min-h-11 items-center rounded px-3 py-3 font-semibold text-blue-800 underline" @if(request()->routeIs('cash.desk')) aria-current="page" @endif>Caja</a>
        </nav>
    @endcan
@endauth
