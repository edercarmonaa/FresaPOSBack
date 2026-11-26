@extends('layouts.app')
@section('content')
    <h1 class="text-2xl font-semibold">Acceso administrativo</h1>
    <livewire:auth.sessions />
    <a href="{{ route('auth.account') }}" class="underline">Volver a tu acceso</a>
@endsection
