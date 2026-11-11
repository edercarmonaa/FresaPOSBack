<?php

namespace Modules\Auth\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/** @property string $pin_hash */
class EmployeePin extends Model
{
    protected $table = 'auth_employee_pins';

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $hidden = ['pin_hash'];
}
