@props(['label' => 'Filtros'])
<x-ui.admin-assets />
<section {{ $attributes->class(['ui-admin-filters'])->merge(['aria-label' => $label]) }}>
    <div class="ui-admin-filter-fields">{{ $slot }}</div>
    @isset($actions)<div class="ui-admin-actions">{{ $actions }}</div>@endisset
</section>
