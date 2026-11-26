@can('reports.view')
    <nav aria-label="Reportes" class="mb-4"><a class="ui-nav-link inline-flex min-h-11 items-center rounded border border-slate-300 bg-white px-4 py-2" href="{{ route('reports.index') }}" aria-label="Reportes" title="Reportes" aria-current="{{ request()->routeIs('reports.*') ? 'page' : 'false' }}"><x-ui.icon name="info" /><span class="ui-nav-label">Reportes</span></a></nav>
@endcan
