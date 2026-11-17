<?php

namespace Modules\Cash\Contracts;

final readonly class SessionSnapshot
{
    public function __construct(
        public string $id,
        public RegisterSnapshot $register,
        public string $openedBy,
        public string $status,
        public string $openingFund,
        public string $openedAt,
        public ?string $closedAt,
        public ?string $closedBy,
    ) {}
}
