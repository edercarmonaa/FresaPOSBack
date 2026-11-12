<section>
    <h2 class="text-xl mt-6 mb-3">Administración de sesiones</h2>
    @can('users.status')
        <form wire:submit="consult" class="my-3">
            <label>ULID del usuario (vacío para tus accesos)
                <input wire:model="userId" class="border p-2" maxlength="26">
            </label>
            <button class="border px-3 py-2">Consultar</button>
        </form>
    @endcan
    <p>Usuario: {{ $identity?->name ?? 'Usuario inactivo o eliminado' }}</p>
    @error('userId')<p role="alert">{{ $message }}</p>@enderror
    @if ($status)<p role="status">{{ $status }}</p>@endif
    <table class="my-3 w-full text-left">
        <thead><tr><th>Acceso</th><th>Inicio UTC</th><th>Última actividad UTC</th><th>Vigencia UTC</th><th>Estado</th><th>Acción</th></tr></thead>
        <tbody>@foreach ($sessions as $access)
            <tr wire:key="{{ $access['id'] }}">
                <td>{{ $access['channel'] }} @if ($access['client_name'])· {{ $access['client_name'] }}@endif</td><td>{{ $access['created_at'] }}</td>
                <td>{{ $access['last_activity_at'] }}</td><td>{{ $access['expires_at'] }}</td><td>{{ ['active' => 'Activo', 'expired' => 'Expirado', 'revoked' => 'Revocado'][$access['state']] }}</td>
                <td>@if ($access['state'] === 'active')<button wire:click="revoke('{{ $access['id'] }}')" wire:confirm="¿Revocar este acceso?" class="underline">Revocar</button>@endif</td>
            </tr>
        @endforeach</tbody>
    </table>
    <button wire:click="revokeOthers" wire:confirm="¿Cerrar tus demás accesos?" class="border px-3 py-2">Cerrar mis otros accesos</button>
    <button wire:click="revokeAll" wire:confirm="¿Revocar todos los accesos del usuario consultado? Deberá autenticarse nuevamente." class="border px-3 py-2">Revocar todos</button>
</section>
