@extends('layouts.app')
@section('content')
    <h1 class="text-2xl font-semibold">Cambiar contraseña</h1>
    <p class="my-4">Utiliza una contraseña nueva de al menos ocho caracteres, con mayúscula, minúscula, número y símbolo.</p>
    @if ($errors->any())<ul role="alert">@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul>@endif
    <form method="POST" action="{{ route('auth.password.update') }}" class="max-w-md space-y-4">
        @csrf
        <label class="block">Contraseña actual<input name="current_password" type="password" autocomplete="current-password" required class="block border p-2 w-full"></label>
        <label class="block">Contraseña nueva<input name="password" type="password" autocomplete="new-password" required class="block border p-2 w-full"></label>
        <label class="block">Confirma la nueva contraseña<input name="password_confirmation" type="password" autocomplete="new-password" required class="block border p-2 w-full"></label>
        <button class="bg-slate-900 text-white rounded px-4 py-2">Guardar contraseña</button>
    </form>
    <form method="POST" action="{{ route('auth.web.logout') }}" class="mt-4">@csrf<button>Cerrar sesión</button></form>
@endsection
