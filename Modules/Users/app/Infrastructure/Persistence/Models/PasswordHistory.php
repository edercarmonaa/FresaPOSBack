<?php

namespace Modules\Users\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** @property string $password_hash */
class PasswordHistory extends Model
{
    use HasUlids;

    protected $table = 'user_password_histories';

    protected $guarded = [];

    protected $hidden = ['password_hash'];
}
