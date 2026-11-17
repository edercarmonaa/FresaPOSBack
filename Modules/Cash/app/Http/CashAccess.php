<?php

namespace Modules\Cash\Http;

use Illuminate\Support\Facades\Auth;
use Modules\Cash\Policies\CashPolicy;
use Modules\Users\Contracts\IdentityAccess;
use Modules\Users\Contracts\UserSnapshot;

final class CashAccess
{
    public function require(string $permission = 'cash.view'): UserSnapshot
    {
        abort_unless(Auth::guard('web')->check(), 401);
        $actor = app(IdentityAccess::class)->inspect((string) Auth::guard('web')->id());
        abort_unless($actor && app(CashPolicy::class)->allows($actor, 'cash.view') && app(CashPolicy::class)->allows($actor, $permission), 403);

        return $actor;
    }
}
