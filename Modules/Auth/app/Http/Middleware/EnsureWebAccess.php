<?php

namespace Modules\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Auth\Application\Authenticate;
use Modules\Auth\Application\SessionRegistry;
use Modules\Auth\Domain\ExpiredSession;
use Modules\Users\Contracts\IdentityAccess;
use Symfony\Component\HttpFoundation\Response;

final class EnsureWebAccess
{
    public function __construct(private readonly SessionRegistry $sessions, private readonly IdentityAccess $identities, private readonly Authenticate $authentication) {}

    public function handle(Request $request, Closure $next): Response
    {
        Auth::shouldUse('web');
        if (! Auth::guard('web')->check()) {
            if ($request->session()->has(Auth::guard('web')->getName())) {
                $this->sessions->retireWebContext($request);
                $this->authentication->clearWeb($request);
            }

            return $this->unauthenticated($request);
        }
        try {
            $this->sessions->web($request);
            $identity = $this->identities->inspect((string) Auth::guard('web')->id());
            if (! $identity) {
                throw new ExpiredSession;
            }
        } catch (ExpiredSession) {
            $this->sessions->retireWebContext($request);
            $this->authentication->clearWeb($request);

            return $this->unauthenticated($request);
        }
        Auth::guard('web')->setUser(Auth::guard('web')->getProvider()->retrieveById($identity->id));
        if ($identity->mustChangePassword && ! $request->routeIs('auth.password', 'auth.password.update', 'auth.web.logout')) {
            if ($request->expectsJson()) {
                abort(403, 'Debes cambiar tu contraseña.');
            }

            return redirect()->route('auth.password');
        }

        return $next($request);
    }

    private function unauthenticated(Request $request): Response
    {
        if ($request->expectsJson()) {
            abort(401);
        }

        return redirect()->guest(route('login'));
    }
}
