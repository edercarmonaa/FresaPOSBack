<?php

namespace Modules\Users\Application;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use LogicException;
use Modules\Users\Domain\PasswordStrength;
use Modules\Users\Infrastructure\Persistence\Models\PasswordHistory;
use Modules\Users\Infrastructure\Persistence\Models\User;

// Componente interno: no expone restablecimientos ni cambios de contraseña al cliente.
final class PasswordHistoryService
{
    public function __construct(private readonly DatabaseManager $database) {}

    public function replace(User $user, #[\SensitiveParameter] string $password): void
    {
        if ($this->database->connection()->transactionLevel() < 1) {
            throw new LogicException('El historial requiere una transacción externa.');
        }
        if (! PasswordStrength::accepts($password)) {
            throw ValidationException::withMessages(['password' => ['La contraseña debe tener al menos 8 caracteres y como máximo 72 bytes, mayúscula, minúscula, número y símbolo, sin caracteres de control.']]);
        }
        if ($user->exists) {
            // Serializa también el futuro cambio propio de contraseña.
            $user = User::query()->lockForUpdate()->findOrFail($user->getKey());
            if (Hash::check($password, $user->password)) {
                throw ValidationException::withMessages(['password' => ['No puedes reutilizar las últimas tres contraseñas.']]);
            }
        }
        $history = PasswordHistory::query()->where('user_id', $user->getKey())->orderByDesc('id')->limit(3)->get();
        foreach ($history as $previous) {
            if (Hash::check($password, $previous->password_hash)) {
                throw ValidationException::withMessages(['password' => ['No puedes reutilizar las últimas tres contraseñas.']]);
            }
        }
        // Hash explícito: tampoco se admite que el caller introduzca un hash como clave.
        $user->password = Hash::make($password);
        $user->save();
        PasswordHistory::query()->create(['user_id' => $user->getKey(), 'password_hash' => $user->password]);
        $keep = PasswordHistory::query()->where('user_id', $user->getKey())->orderByDesc('id')->limit(3)->pluck('id');
        PasswordHistory::query()->where('user_id', $user->getKey())->whereNotIn('id', $keep)->delete();
    }
}
