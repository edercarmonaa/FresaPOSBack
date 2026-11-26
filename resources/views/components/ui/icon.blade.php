@props(['name' => 'info'])
@php
    $paths = [
        'info' => 'M12 11v6m0-10v.01M22 12a10 10 0 1 1-20 0 10 10 0 0 1 20 0',
        'check' => 'm5 12 4 4L19 6',
        'warning' => 'm12 3 10 18H2L12 3m0 6v5m0 3v.01',
        'plus' => 'M12 5v14M5 12h14',
        'search' => 'M21 21l-5-5m2-6a8 8 0 1 1-16 0 8 8 0 0 1 16 0',
        'close' => 'm6 6 12 12M6 18 18 6',
        'sun' => 'M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1.5 1.5m11 11L19 19M5 19l1.5-1.5m11-11L19 5m-3 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0',
        'menu' => 'M4 6h16M4 12h16M4 18h16',
    ];
@endphp
<svg {{ $attributes->class(['ui-icon']) }} viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="{{ $paths[$name] ?? $paths['info'] }}" /></svg>
