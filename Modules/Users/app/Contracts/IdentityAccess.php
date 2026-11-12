<?php

namespace Modules\Users\Contracts;

interface IdentityAccess
{
    /** Serializa cambios de identidad y emisión/revocación dentro de una transacción Core. */
    public function lock(): void;

    public function verify(string $username, #[\SensitiveParameter] string $password): ?UserSnapshot;

    public function inspect(string $id): ?UserSnapshot;

    public function byUsername(string $username): ?UserSnapshot;

    public function changePassword(string $id, #[\SensitiveParameter] string $currentPassword, #[\SensitiveParameter] string $newPassword): UserSnapshot;
}
