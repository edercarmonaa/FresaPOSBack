@extends('layouts.app')
@section('content')
    <h1 class="text-2xl font-semibold">Acceso administrativo</h1>
    <p class="my-4">Tu identidad tiene permiso para consultar usuarios. La administración completa se habilitará en su etapa.</p>
    <a href="{{ route('auth.account') }}" class="underline">Volver a tu acceso</a>
@endsection
