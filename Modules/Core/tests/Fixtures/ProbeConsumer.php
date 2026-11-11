<?php

namespace Modules\Core\Tests\Fixtures;

final class ProbeConsumer
{
    public function __construct(public readonly ProbeContract $probe) {}
}
