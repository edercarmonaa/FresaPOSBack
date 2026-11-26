<section class="ui-auth-card ui-auth-sessions">
    <h2 class="ui-auth-subtitle">Administración de sesiones</h2>
    @can('users.status')
        <form class="ui-auth-form" wire:submit="consult">
            <label>ULID del usuario (vacío para tus accesos)
                <input class="ui-auth-input" wire:model="userId"  maxlength="26">
            </label>
            <button type="submit" class="ui-button ui-button--primary">Consultar</button>
        </form>
    @endcan
    <p>Usuario: {{ $identity?->name ?? 'Usuario inactivo o eliminado' }}</p>
    @error('userId')<p role="alert">{{ $message }}</p>@enderror
    @if ($status)<p class="ui-auth-status" role="status">{{ $status }}</p>@endif
    <div class="ui-auth-table" role="region" aria-label="Sesiones de acceso" tabindex="0">
    <table><caption>Accesos del usuario consultado · Fechas en UTC</caption>
        <thead><tr><th scope="col">Acceso</th><th scope="col">Inicio UTC</th><th scope="col">Última actividad UTC</th><th scope="col">Vigencia UTC</th><th scope="col">Estado</th><th scope="col">Acción</th></tr></thead>
        <tbody>@forelse ($sessions as $access)
            <tr wire:key="{{ $access['id'] }}">
                <td>{{ $access['channel'] }} @if ($access['client_name'])· {{ $access['client_name'] }}@endif</td><td>{{ $access['created_at'] }}</td>
                <td>{{ $access['last_activity_at'] }}</td><td>{{ $access['expires_at'] }}</td><td>{{ ['active' => 'Activo', 'expired' => 'Expirado', 'revoked' => 'Revocado'][$access['state']] }}</td>
                <td>@if ($access['state'] === 'active')<button type="button" class="ui-button ui-button--secondary" wire:click="revoke('{{ $access['id'] }}')" wire:confirm="¿Revocar este acceso?">Revocar</button>@endif</td>
            </tr>
        @empty
            <tr><td colspan="6">No hay accesos para mostrar.</td></tr>
        @endforelse</tbody>
    </table>
    </div>
    <div class="ui-auth-actions">
    <button type="button" class="ui-button ui-button--secondary" wire:click="revokeOthers" wire:confirm="¿Cerrar tus demás accesos?">Cerrar mis otros accesos</button>
    <button type="button" class="ui-button ui-button--secondary" wire:click="revokeAll" wire:confirm="¿Revocar todos los accesos del usuario consultado? Deberá autenticarse nuevamente.">Revocar todos</button>
    </div>
</section>
