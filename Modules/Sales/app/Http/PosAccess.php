<?php

namespace Modules\Sales\Http;

use Illuminate\Support\Facades\Auth;
use Modules\Sales\Policies\SalesPolicy;
use Modules\Users\Contracts\IdentityAccess;
use Modules\Users\Contracts\UserSnapshot;

final class PosAccess
{
    public function require(string $permission = 'sales.view'): UserSnapshot
    {
        abort_unless(Auth::guard('web')->check(), 401);
        $actor = app(IdentityAccess::class)->inspect((string) Auth::guard('web')->id());
        $policy = app(SalesPolicy::class);
        abort_unless($actor && $policy->allows($actor, 'sales.view') && $policy->allows($actor, 'catalog.products.view') && $policy->allows($actor, $permission), 403);

        return $actor;
    }
}
