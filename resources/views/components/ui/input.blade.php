@props(['invalid' => false])
<input {{ $attributes->class(['ui-input'])->merge(array_merge(['type' => 'text'], $invalid ? ['aria-invalid' => 'true'] : [])) }}>
