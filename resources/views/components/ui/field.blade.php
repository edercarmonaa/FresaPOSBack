@props(['inputId', 'label', 'hint' => null, 'error' => null, 'required' => false])
<div {{ $attributes->class(['ui-field']) }}>
    <label class="ui-label" for="{{ $inputId }}">{{ $label }}@if ($required) <span>(obligatorio)</span>@endif</label>
    {{ $slot }}
    @if ($hint)<p class="ui-help" id="{{ $inputId }}-help">{{ $hint }}</p>@endif
    @if ($error)<p class="ui-field-error" id="{{ $inputId }}-error">{{ $error }}</p>@endif
</div>
