@props(['tone' => 'info', 'title' => null])
@php($tone = in_array($tone, ['info', 'success', 'warning', 'error'], true) ? $tone : 'info')
<div {{ $attributes->class(['ui-alert', 'ui-alert--'.$tone]) }}>
    <x-ui.icon :name="['success' => 'check', 'warning' => 'warning', 'error' => 'warning'][$tone] ?? 'info'" />
    <div>@if ($title)<strong class="ui-alert-title">{{ $title }}</strong>@endif{{ $slot }}</div>
</div>
