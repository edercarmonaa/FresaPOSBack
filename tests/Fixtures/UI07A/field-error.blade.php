@php
    $fieldMessages = [];
    foreach ([$field, ...($aliases ?? [])] as $errorKey) {
        $fieldMessages = array_merge($fieldMessages, $errors->get($errorKey));
    }
@endphp
<p id="{{ $id }}" class="mt-1 text-sm text-red-800" aria-live="polite" @if($fieldMessages === []) hidden @endif>{{ implode(' ', array_unique($fieldMessages)) }}</p>
