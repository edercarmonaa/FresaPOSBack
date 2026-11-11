<?php

namespace Modules\Core\Tests\Fixtures;

final class Probe implements ProbeContract
{
    public function module(): string
    {
        return 'Core';
    }
}
