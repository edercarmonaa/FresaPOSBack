<?php

namespace Modules\Sales\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class PosAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        app(\Modules\Sales\Http\PosAccess::class)->require();

        return $next($request);
    }
}
