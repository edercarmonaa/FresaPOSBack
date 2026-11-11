<?php

namespace Modules\Users\Contracts;

interface UserAdministration
{
    /** @param array{name:string,username:string,password:string} $data */
    public function create(string $actorId, #[\SensitiveParameter] array $data): UserSnapshot;

    public function find(string $actorId, string $userId): UserSnapshot;

    /** @return list<UserSnapshot> */
    public function list(string $actorId, int $page = 1): array;

    /** @param array{name?:string,username?:string} $data */
    public function update(string $actorId, string $userId, array $data): UserSnapshot;

    public function setActive(string $actorId, string $userId, bool $active): UserSnapshot;

    public function assignRole(string $actorId, string $userId, string $role): UserSnapshot;

    public function removeRole(string $actorId, string $userId, string $role): UserSnapshot;

    public function delete(string $actorId, string $userId): void;

    /** @return list<string> */
    public function permissions(string $actorId, string $userId): array;

    /** @return list<array{name:string,label:string}> */
    public function roles(string $actorId): array;

    public function createRole(string $actorId, string $name, string $label): void;

    /** @param list<string> $permissions */
    public function setRolePermissions(string $actorId, string $role, array $permissions): void;
}
