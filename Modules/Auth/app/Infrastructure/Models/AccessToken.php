<?php

namespace Modules\Auth\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * @property string $tokenable_id
 * @property string $tokenable_type
 * @property Carbon $expires_at
 */
class AccessToken extends PersonalAccessToken
{
    use HasUlids;

    protected $table = 'personal_access_tokens';

    protected $hidden = ['token'];
}
