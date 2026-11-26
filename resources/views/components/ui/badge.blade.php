@props(['tone' => 'neutral'])
@php($tone = in_array($tone, ['neutral', 'info', 'success', 'warning', 'error'], true) ? $tone : 'neutral')
<span {{ $attributes->class(['ui-badge', 'ui-badge--'.$tone]) }}>{{ $slot }}</span>
