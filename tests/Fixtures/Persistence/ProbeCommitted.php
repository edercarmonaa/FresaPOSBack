<?php

namespace Tests\Fixtures\Persistence;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final readonly class ProbeCommitted implements ShouldDispatchAfterCommit
{
    public function __construct(public string $id) {}
}
