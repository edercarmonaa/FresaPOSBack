<?php

namespace Modules\Auth\Infrastructure\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $user_id
 * @property ?string $token_id
 * @property ?string $terminal_owner_id
 * @property ?string $session_hash
 * @property string $channel
 * @property Carbon $created_at
 * @property Carbon $last_activity_at
 * @property Carbon $expires_at
 * @property ?Carbon $revoked_at
 */
class AccessSession extends Model
{
    use HasUlids;

    protected $table = 'auth_access_sessions';

    protected $guarded = [];

    protected $hidden = ['session_hash', 'token_id'];

    protected function casts(): array
    {
        return ['last_activity_at' => 'datetime', 'expires_at' => 'datetime', 'revoked_at' => 'datetime'];
    }
}
