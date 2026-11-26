<!DOCTYPE html>
<html lang="es" class="ui-root">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name') }}</title>
    <script src="{{ asset('js/ui/theme.js') }}"></script>
    <link rel="preload" href="{{ asset('fonts/ui/InterVariable.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="{{ asset('css/ui/tokens.css') }}">
    <link rel="stylesheet" href="{{ asset('css/ui/components.css') }}">
    <link rel="stylesheet" href="{{ asset('css/ui/layouts.css') }}">
    <script src="{{ asset('js/ui/layout.js') }}" defer></script>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4.1.18"></script>
    @stack('styles')
    @livewireStyles
</head>
@php
    $uiContext = request()->routeIs('sales.pos') ? 'pos' : (request()->routeIs('login', 'auth.password') ? 'auth' : 'admin');
    $uiNavigation = view('layouts.partials.navigation')->render();
    $uiHasNavigation = trim($uiNavigation) !== '';
@endphp
<body class="ui-page" data-ui-context="{{ $uiContext }}">
    <a class="ui-skip-link" href="#ui-main">Saltar al contenido</a>
    @include('layouts.partials.header')
    <div class="ui-shell {{ $uiHasNavigation ? 'ui-shell--with-navigation' : '' }}">
        @if ($uiHasNavigation)
            <aside class="ui-navigation" id="ui-navigation" aria-label="Navegación principal">
                <div class="ui-navigation-heading"><span>Espacio de trabajo</span><button type="button" class="ui-shell-button" data-ui-nav-close aria-label="Cerrar menú"><x-ui.icon name="close" /></button></div>
                {!! $uiNavigation !!}
            </aside>
            <button type="button" class="ui-nav-backdrop" data-ui-nav-close aria-label="Cerrar menú" tabindex="-1" hidden></button>
        @endif
        <main id="ui-main" tabindex="-1" class="ui-content {{ $uiContext === 'pos' ? 'ui-legacy-light' : '' }} @yield('mainClass', 'mx-auto max-w-4xl p-8')">
            @yield('content')
        </main>
    </div>
    @include('layouts.partials.preferences')
    @livewireScripts
</body>
</html>
