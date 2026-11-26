@props(['invalid' => false])
<textarea {{ $attributes->class(['ui-textarea'])->merge(array_merge(['rows' => '4'], $invalid ? ['aria-invalid' => 'true'] : [])) }}>{{ $slot }}</textarea>
