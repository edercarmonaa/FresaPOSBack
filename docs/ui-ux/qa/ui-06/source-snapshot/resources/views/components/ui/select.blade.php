@props(['invalid' => false])
<select {{ $attributes->class(['ui-select'])->merge($invalid ? ['aria-invalid' => 'true'] : []) }}>{{ $slot }}</select>
