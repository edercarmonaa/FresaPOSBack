<?php

namespace Modules\Users\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;

/** @property string $label */
class Role extends \Spatie\Permission\Models\Role
{
    use HasUlids;

    protected static function booted(): void
    {
        static::creating(function (self $role): void {
            if (! is_string($role->getAttribute('label')) || $role->getAttribute('label') === '') {
                $role->label = config('users.security.labels.'.$role->name, $role->name);
            }
        });
    }
}
