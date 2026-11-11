<?php

namespace Modules\Users\Contracts;

final readonly class UserSnapshot
{
    /** @param list<string> $roles
     * @param  list<string>  $permissions
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $username,
        public bool $isActive,
        public bool $mustChangePassword,
        public array $roles,
        public array $permissions,
    ) {}
}
