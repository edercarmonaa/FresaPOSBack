<?php

namespace Modules\Users\Contracts;

interface IdentityLabels
{
    /** @return array<string,string> Nombres disponibles de identidades, no nombres congelados. */
    public function names(array $ids): array;
}
