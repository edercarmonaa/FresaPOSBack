<?php

namespace Modules\Users\Infrastructure\Persistence\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;

class Permission extends \Spatie\Permission\Models\Permission
{
    use HasUlids;
}
