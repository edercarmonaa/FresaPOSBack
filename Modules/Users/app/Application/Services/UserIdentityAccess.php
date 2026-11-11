<?php

namespace Modules\Users\Application\Services;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Timebox;
use Illuminate\Validation\ValidationException;
use Modules\Core\Contracts\TransactionManager;
use Modules\Users\Application\PasswordHistoryService;
use Modules\Users\Application\Ports\SecurityStore;
use Modules\Users\Contracts\IdentityAccess;
use Modules\Users\Contracts\UserSnapshot;
use Modules\Users\Domain\Username;
use Modules\Users\Infrastructure\Persistence\Models\User;

final class UserIdentityAccess implements IdentityAccess
{
    // Hash de una cadena aleatoria descartada; no es una credencial de usuario.
    private const DUMMY_HASH = '$2y$12$goDt1fVqJ2cvE9LKb0fcJOP8GDnyWZh4JQFQ3vVZY7gR54CdA9E8G';

    public function __construct(private readonly TransactionManager $transactions, private readonly SecurityStore $store, private readonly PasswordHistoryService $passwords) {}

    public function verify(string $username, #[\SensitiveParameter] string $password): ?UserSnapshot
    {
        return (new Timebox)->call(function () use ($username, $password): ?UserSnapshot {
            $normalized = Username::normalize($username);
            $user = preg_match('/\A[a-z0-9._-]{3,64}\z/', $normalized) === 1
                ? User::withTrashed()->where('username', $normalized)->first() : null;
            $validInput = strlen($password) <= 72 && ! str_contains($password, "\0");
            $matches = Hash::check($validInput ? $password : 'invalid-password', $user->password ?? self::DUMMY_HASH);

            return $validInput && $matches && $user && $user->is_active && ! $user->trashed() ? $this->snapshot($user) : null;
        }, 250000);
    }

    public function inspect(string $id): ?UserSnapshot
    {
        $user = User::query()->where('is_active', true)->find($id);

        return $user ? $this->snapshot($user) : null;
    }

    public function byUsername(string $username): ?UserSnapshot
    {
        $normalized = Username::normalize($username);
        if (preg_match('/\A[a-z0-9._-]{3,64}\z/', $normalized) !== 1) {
            return null;
        }
        $user = User::query()->where('is_active', true)->where('username', $normalized)->first();

        return $user ? $this->snapshot($user) : null;
    }

    public function changePassword(string $id, #[\SensitiveParameter] string $currentPassword, #[\SensitiveParameter] string $newPassword): UserSnapshot
    {
        return $this->transactions->run(function () use ($id, $currentPassword, $newPassword): UserSnapshot {
            $this->store->lock();
            $user = User::query()->where('is_active', true)->lockForUpdate()->findOrFail($id);
            if (strlen($currentPassword) > 72 || str_contains($currentPassword, "\0") || ! Hash::check($currentPassword, $user->password)) {
                throw ValidationException::withMessages(['current_password' => ['No fue posible verificar las credenciales.']]);
            }
            $this->passwords->replace($user, $newPassword);
            $user->refresh();
            $user->must_change_password = false;
            $user->save();

            return $this->snapshot($user);
        });
    }

    private function snapshot(User $user): UserSnapshot
    {
        return new UserSnapshot($user->id, $user->name, $user->username, $user->is_active, $user->must_change_password,
            $user->getRoleNames()->sort()->values()->all(), $user->getAllPermissions()->pluck('name')->sort()->values()->all());
    }
}
