<?php

namespace Modules\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class NoStore
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if(! app()->environment('local', 'testing') && ! $request->isSecure(), 400);
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
