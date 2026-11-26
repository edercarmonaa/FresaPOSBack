@props(['title', 'description' => null, 'kind' => 'empty'])
@php($kind = in_array($kind, ['empty', 'loading', 'error'], true) ? $kind : 'empty')
<x-ui.admin-assets />
<section {{ $attributes->class(['ui-admin-state', 'ui-admin-state--'.$kind]) }} @if ($kind === 'loading') aria-busy="true" @endif>
    <x-ui.icon :name="$kind === 'error' ? 'warning' : 'info'" />
    <h2>{{ $title }}</h2>
    @if ($description)<p class="ui-muted">{{ $description }}</p>@endif
    @if ($kind === 'loading')<div class="ui-admin-skeleton" aria-hidden="true"><span></span><span></span><span></span></div>@endif
    {{ $slot }}
</section>
