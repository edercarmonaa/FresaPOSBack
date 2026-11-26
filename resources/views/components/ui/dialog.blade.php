@props(['id', 'title', 'description' => null, 'variant' => 'modal'])
@php($variant = in_array($variant, ['modal', 'drawer'], true) ? $variant : 'modal')
<x-ui.admin-assets />
<dialog id="{{ $id }}" {{ $attributes->class(['ui-admin-dialog', 'ui-admin-dialog--'.$variant])->merge(['data-ui-dialog' => '', 'aria-labelledby' => $id.'-title']) }} @if ($description) aria-describedby="{{ $id }}-description" @endif>
    <div class="ui-admin-dialog-heading">
        <h2 id="{{ $id }}-title">{{ $title }}</h2>
        <x-ui.button variant="secondary" data-ui-dialog-close aria-label="Cerrar {{ $title }}"><x-ui.icon name="close" /></x-ui.button>
    </div>
    @if ($description)<p class="ui-muted" id="{{ $id }}-description">{{ $description }}</p>@endif
    <div class="ui-admin-dialog-body">{{ $slot }}</div>
    @isset($footer)<div class="ui-admin-actions ui-admin-dialog-footer">{{ $footer }}</div>@endisset
</dialog>
