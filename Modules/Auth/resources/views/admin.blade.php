@extends('layouts.app')
@section('mainClass', 'ui-auth-page')
@push('styles')
    <link rel="stylesheet" href="{{ asset('css/ui/auth.css') }}">
@endpush
@section('content')
    <header class="ui-auth-card">
    <h1 class="ui-auth-title">Acceso administrativo</h1>
    </header>
    <livewire:auth.sessions />
    <a href="{{ route('auth.account') }}">Volver a tu acceso</a>
@endsection
