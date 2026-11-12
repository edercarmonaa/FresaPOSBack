<?php

namespace Modules\Users\Contracts;

final readonly class UserAccessChanged
{
    public function __construct(public string $userId, public ?string $actorId = null, public string $reason = 'access.changed') {}
}
