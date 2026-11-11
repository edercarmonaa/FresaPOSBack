<?php

namespace Modules\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Auth\Application\SessionRegistry;
use Modules\Auth\Domain\ExpiredSession;
use Modules\Auth\Domain\PasswordChangeRequired;
use Modules\Users\Contracts\IdentityAccess;
use Symfony\Component\HttpFoundation\Response;

final class EnsureApiAccess
{
    public function __construct(private readonly SessionRegistry $sessions, private readonly IdentityAccess $identities) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $this->identities->inspect((string) $request->user()->getAuthIdentifier());
        if (! $user) {
            throw new ExpiredSession;
        }
        $this->sessions->api($request);
        $recovery = $request->routeIs('api.v1.auth.me', 'api.v1.auth.logout', 'api.v1.auth.password');
        if (! $recovery && $user->mustChangePassword) {
            throw new PasswordChangeRequired;
        }
        abort_unless($recovery || $this->sessions->token($request)->can('access'), 403);

        return $next($request);
    }
}
