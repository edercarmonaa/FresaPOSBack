@extends('layouts.app')
@section('mainClass', 'ui-auth-page')
@push('styles')
    <link rel="stylesheet" href="{{ asset('css/ui/auth.css') }}">
@endpush
@section('content')
    <div class="ui-auth-card ui-auth-login"><p class="ui-auth-eyebrow">FresaPOS · Acceso al sistema</p>
    <h1 class="ui-auth-title">Iniciar sesión</h1>
    <p class="ui-auth-intro">Ingresa con tu nombre de usuario y contraseña.</p>
    <livewire:auth.login />
    </div>
@endsection
