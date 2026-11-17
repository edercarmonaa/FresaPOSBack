<?php

namespace Modules\Cash\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class CashAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        app(\Modules\Cash\Http\CashAccess::class)->require();

        return $next($request);
    }
}
