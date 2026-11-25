<?php

namespace Modules\Users\Infrastructure\Persistence;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Users\Contracts\IdentityLabels;

final class ReadIdentityLabels implements IdentityLabels
{
    public function names(array $ids): array
    {
        $ids = array_values(array_unique($ids));
        if (count($ids) > 1000 || array_filter($ids, fn ($id) => ! is_string($id) || ! Str::isUlid($id)) !== []) {
            throw new \InvalidArgumentException('Lista de identidades inválida.');
        }

        return $ids === [] ? [] : DB::table('users')->whereIn('id', $ids)->pluck('name', 'id')->all();
    }
}
