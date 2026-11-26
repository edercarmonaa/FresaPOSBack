@props(['title' => null, 'tone' => 'info', 'live' => 'off', 'dismissible' => false, 'floating' => false, 'returnFocus' => null])
@php($live = in_array($live, ['off', 'polite', 'assertive'], true) ? $live : 'off')
<x-ui.admin-assets />
<div {{ $attributes->class(['ui-admin-notice', 'ui-admin-notice--floating' => $floating]) }} data-ui-notice data-ui-notice-return="{{ $returnFocus }}" data-ui-dismissible="{{ $dismissible ? 'true' : 'false' }}">
    <x-ui.alert :tone="$tone" :title="$title" :aria-live="$live" :aria-atomic="$live !== 'off' ? 'true' : 'false'">{{ $slot }}</x-ui.alert>
    @if ($dismissible)<x-ui.button variant="secondary" data-ui-notice-dismiss aria-label="Cerrar notificación"><x-ui.icon name="close" /></x-ui.button>@endif
</div>
