<?php

namespace Modules\Reports\Contracts;

final readonly class GeneratedReport
{
    public function __construct(public string $contents, public string $mime, public string $filename) {}
}
