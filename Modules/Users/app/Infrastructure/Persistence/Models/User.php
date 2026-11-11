<?php

namespace Modules\Users\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Modules\Users\Domain\SecurityConflict;
use Modules\Users\Domain\Username;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property string $id
 * @property string $name
 * @property string $username
 * @property string $password
 * @property bool $is_active
 * @property bool $must_change_password
 */
class User extends Authenticatable
{
    use HasApiTokens;
    use HasRoles;
    use HasUlids;
    use SoftDeletes;

    protected $guard_name = 'web';

    protected $fillable = ['name', 'username'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['password' => 'hashed', 'is_active' => 'boolean', 'must_change_password' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $user): void {
            $user->username = Username::normalize($user->username);
            $user->name = trim($user->name);
        });
    }

    // Rechazar antes de eventos: Spatie podría retirar pivots antes de un listener deleting.
    public function forceDelete(): never
    {
        throw new SecurityConflict('La eliminación física de usuarios está prohibida.');
    }
}
