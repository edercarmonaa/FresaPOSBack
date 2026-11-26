@extends('layouts.app')
@section('content')
    <h1 class="text-2xl font-semibold">Tu acceso</h1>
    <p class="my-3">Empleado activo: <strong>{{ $identity->name }}</strong> ({{ $identity->username }})</p>
    <p>Roles: {{ implode(', ', $identity->roles) }}</p>
    @if (session('status'))<p role="status">{{ session('status') }}</p>@endif
    @if ($errors->any())<ul role="alert">@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>@endif
    @can('users.view')<a href="{{ route('auth.admin') }}" class="underline">Acceso administrativo</a>@endcan
    <a href="{{ route('auth.password') }}" class="underline ml-3">Cambiar contraseña</a>
    <h2 class="text-xl mt-6 mb-3">PIN personal</h2>
    <form method="POST" action="{{ route('auth.pin') }}" class="space-y-2 max-w-md">@csrf
        <label class="block">Confirma tu contraseña<input name="current_password" type="password" autocomplete="current-password" required class="border block p-2 w-full"></label>
        <label class="block">PIN nuevo (4 a 6 dígitos)<input name="pin" type="password" inputmode="numeric" pattern="[0-9]{4,6}" autocomplete="off" required class="border block p-2 w-full"></label>
        <label class="block">Confirma el PIN<input name="pin_confirmation" type="password" inputmode="numeric" autocomplete="off" required class="border block p-2 w-full"></label>
        <button class="border rounded px-3 py-2">Guardar PIN</button>
    </form>
    <form method="POST" action="{{ route('auth.pin.disable') }}" class="my-3">@csrf<label>Confirma tu contraseña para deshabilitar el PIN <input name="current_password" type="password" autocomplete="current-password" required class="border p-2"></label><button class="border px-3 py-2">Deshabilitar PIN</button></form>
    @can('cash.open')
        <h2 class="text-xl mt-6 mb-3">Autorizar esta sesión como terminal</h2>
        <form method="POST" action="{{ route('auth.terminal') }}">@csrf<label>Contraseña <input name="current_password" type="password" autocomplete="current-password" required class="border p-2"></label><button class="border px-3 py-2">Autorizar terminal</button></form>
    @endcan
    <h2 class="text-xl mt-6 mb-3">Cambiar empleado</h2>
    <p>Requiere una sesión de terminal autorizada. El acceso administrativo requiere contraseña completa.</p>
    <form method="POST" action="{{ route('auth.employee') }}" class="my-3 space-y-2">@csrf
        <label>Usuario entrante <input name="username" autocomplete="off" required class="border p-2"></label>
        <label>PIN <input name="pin" type="password" inputmode="numeric" autocomplete="off" required class="border p-2"></label>
        <button class="border px-3 py-2">Cambiar empleado</button>
    </form>
    <livewire:auth.sessions />
    <form method="POST" action="{{ route('auth.web.logout') }}" class="mt-6">@csrf<button class="bg-slate-900 text-white rounded px-4 py-2">Cerrar sesión</button></form>
@endsection
