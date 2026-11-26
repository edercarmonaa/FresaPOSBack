@extends('layouts.app')
@section('mainClass', 'ui-auth-page')
@push('styles')
    <link rel="stylesheet" href="{{ asset('css/ui/auth.css') }}">
@endpush
@section('content')
    <header class="ui-auth-card">
    <h1 class="ui-auth-title">Tu acceso</h1>
    <p>Empleado activo: <strong>{{ $identity->name }}</strong> ({{ $identity->username }})</p>
    <p>Roles: {{ implode(', ', $identity->roles) }}</p>
    @if (session('status'))<p class="ui-auth-status" role="status">{{ session('status') }}</p>@endif
    @if ($errors->any())<ul class="ui-auth-errors" role="alert">@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>@endif
    @can('users.view')<a href="{{ route('auth.admin') }}">Acceso administrativo</a>@endcan
    <a href="{{ route('auth.password') }}">Cambiar contraseña</a>
    </header>
    <div class="ui-auth-sections">
    <section class="ui-auth-card">
    <h2 class="ui-auth-subtitle">PIN personal</h2>
    <form class="ui-auth-form" method="POST" action="{{ route('auth.pin') }}">@csrf
        <label>Confirma tu contraseña<input class="ui-auth-input" name="current_password" type="password" autocomplete="current-password" required></label>
        <label>PIN nuevo (4 a 6 dígitos)<input class="ui-auth-input" name="pin" type="password" inputmode="numeric" pattern="[0-9]{4,6}" autocomplete="off" required></label>
        <label>Confirma el PIN<input class="ui-auth-input" name="pin_confirmation" type="password" inputmode="numeric" autocomplete="off" required></label>
        <button type="submit" class="ui-button ui-button--primary">Guardar PIN</button>
    </form>
    <form class="ui-auth-form" method="POST" action="{{ route('auth.pin.disable') }}">@csrf<label>Confirma tu contraseña para deshabilitar el PIN <input class="ui-auth-input" name="current_password" type="password" autocomplete="current-password" required></label><button type="submit" class="ui-button ui-button--primary">Deshabilitar PIN</button></form>
    </section>
    @can('cash.open')
    <section class="ui-auth-card">
        <h2 class="ui-auth-subtitle">Autorizar esta sesión como terminal</h2>
        <form class="ui-auth-form" method="POST" action="{{ route('auth.terminal') }}">@csrf<label>Contraseña <input class="ui-auth-input" name="current_password" type="password" autocomplete="current-password" required></label><button type="submit" class="ui-button ui-button--primary">Autorizar terminal</button></form>
    </section>
    @endcan
    <section class="ui-auth-card">
    <h2 class="ui-auth-subtitle">Cambiar empleado</h2>
    <p>Requiere una sesión de terminal autorizada. El acceso administrativo requiere contraseña completa.</p>
    <form class="ui-auth-form" method="POST" action="{{ route('auth.employee') }}">@csrf
        <label>Usuario entrante <input class="ui-auth-input" name="username" autocomplete="off" required></label>
        <label>PIN <input class="ui-auth-input" name="pin" type="password" inputmode="numeric" autocomplete="off" required></label>
        <button type="submit" class="ui-button ui-button--primary">Cambiar empleado</button>
    </form>
    </section>
    </div>
    <livewire:auth.sessions />
    <form class="ui-auth-form" method="POST" action="{{ route('auth.web.logout') }}">@csrf<button type="submit" class="ui-button ui-button--primary">Cerrar sesión</button></form>
@endsection
