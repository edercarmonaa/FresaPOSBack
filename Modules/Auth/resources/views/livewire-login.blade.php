<form class="ui-auth-form" wire:submit="login" method="POST" action="{{ route('auth.web.login') }}">
    @csrf
    <div><label for="username">Nombre de usuario</label><input class="ui-auth-input" id="username" aria-invalid="{{ $errors->has('username') ? 'true' : 'false' }}" @error('username') aria-describedby="ui-login-username-error" @enderror name="username" wire:model="username" autocomplete="username" maxlength="64" required>@error('username')<p class="ui-auth-error" id="ui-login-username-error" role="alert">{{ $message }}</p>@enderror</div>
    <div><label for="password">Contraseña</label><input class="ui-auth-input" id="password" aria-invalid="{{ $errors->has('password') ? 'true' : 'false' }}" @error('password') aria-describedby="ui-login-password-error" @enderror name="password" wire:model="password" type="password" autocomplete="current-password" maxlength="72" required>@error('password')<p class="ui-auth-error" id="ui-login-password-error" role="alert">{{ $message }}</p>@enderror</div>
    <button type="submit" class="ui-button ui-button--primary" wire:loading.attr="disabled">Entrar</button>
</form>
