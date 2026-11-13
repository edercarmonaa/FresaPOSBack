<?php

namespace Modules\Catalog\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Catalog\Http\CatalogAccess;
use Symfony\Component\HttpFoundation\Response;

final class CatalogImageUploadAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        app(CatalogAccess::class)->web('products');
        app(CatalogAccess::class)->requireAny(['catalog.products.update']);
        $response = $next($request);
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
