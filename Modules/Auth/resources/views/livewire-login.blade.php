<form wire:submit="login" method="POST" action="{{ route('auth.web.login') }}" class="max-w-md space-y-4">
    @csrf
    <div><label for="username">Nombre de usuario</label><input id="username" name="username" wire:model="username" autocomplete="username" maxlength="64" required class="block border rounded p-2 w-full">@error('username')<p role="alert">{{ $message }}</p>@enderror</div>
    <div><label for="password">Contraseña</label><input id="password" name="password" wire:model="password" type="password" autocomplete="current-password" maxlength="72" required class="block border rounded p-2 w-full">@error('password')<p role="alert">{{ $message }}</p>@enderror</div>
    <button type="submit" wire:loading.attr="disabled" class="bg-slate-900 text-white rounded px-4 py-2">Entrar</button>
</form>
