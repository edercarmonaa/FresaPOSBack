@props(['label' => 'Paginación'])
<x-ui.admin-assets />
<nav {{ $attributes->class(['ui-admin-pagination'])->merge(['aria-label' => $label]) }}>
    @isset($summary)<p class="ui-muted">{{ $summary }}</p>@endisset
    <div class="ui-admin-actions">{{ $slot }}</div>
</nav>
