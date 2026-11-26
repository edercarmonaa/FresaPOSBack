@props(['variant' => 'primary'])
@php($variant = in_array($variant, ['primary', 'secondary', 'danger'], true) ? $variant : 'primary')
<button {{ $attributes->class(['ui-button', 'ui-button--'.$variant])->merge(['type' => 'button']) }}>{{ $slot }}</button>
