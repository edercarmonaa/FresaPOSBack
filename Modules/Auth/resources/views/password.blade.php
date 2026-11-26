@extends('layouts.app')
@section('mainClass', 'ui-auth-page')
@push('styles')
    <link rel="stylesheet" href="{{ asset('css/ui/auth.css') }}">
@endpush
@section('content')
    <div class="ui-auth-card">
    <h1 class="ui-auth-title">Cambiar contraseña</h1>
    <p>Utiliza una contraseña nueva de al menos ocho caracteres, con mayúscula, minúscula, número y símbolo.</p>
    @if ($errors->any())<ul class="ui-auth-errors" role="alert">@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>@endif
    <form class="ui-auth-form" method="POST" action="{{ route('auth.password.update') }}">
        @csrf
        <label>Contraseña actual<input class="ui-auth-input" name="current_password" type="password" autocomplete="current-password" required></label>
        <label>Contraseña nueva<input class="ui-auth-input" name="password" type="password" autocomplete="new-password" required></label>
        <label>Confirma la nueva contraseña<input class="ui-auth-input" name="password_confirmation" type="password" autocomplete="new-password" required></label>
        <button type="submit" class="ui-button ui-button--primary">Guardar contraseña</button>
    </form>
    <form class="ui-auth-form" method="POST" action="{{ route('auth.web.logout') }}">@csrf<button type="submit" class="ui-button ui-button--primary">Cerrar sesión</button></form>
    </div>
@endsection
