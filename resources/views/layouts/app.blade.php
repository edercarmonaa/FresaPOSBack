<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('app.name') }}</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4.1.18"></script>
    @livewireStyles
</head>
<body class="bg-slate-50 text-slate-900">
    <main class="mx-auto max-w-4xl p-8">
        @yield('content')
    </main>
    @livewireScripts
</body>
</html>
