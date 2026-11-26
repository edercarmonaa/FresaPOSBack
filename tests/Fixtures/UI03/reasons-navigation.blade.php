@auth('web')
    @can('settings.update')
        @can('sales.view')
            <nav aria-label="Administración de ventas" class="mb-6 flex flex-wrap gap-4 border-b pb-4"><a class="rounded px-3 py-3 text-blue-700 underline" href="{{ route('sales.reasons') }}" @if(request()->routeIs('sales.reasons')) aria-current="page" @endif>Motivos autorizados de ventas</a></nav>
        @endcan
    @endcan
@endauth
