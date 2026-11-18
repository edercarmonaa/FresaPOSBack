<?php

namespace Modules\Sales\Contracts;

interface SaleOperationReasons
{
    public function search(string $actorId, array $filters = []): array;

    public function find(string $actorId, string $id): array;

    public function write(string $actorId, string $action, array $data, string $key, ?string $id = null, ?string $revision = null): array;

    /** Exige transacción externa y devuelve la instantánea autorizada vigente. */
    public function approved(string $actorId, string $operation, string $code): array;
}
