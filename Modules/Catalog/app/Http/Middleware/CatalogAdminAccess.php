<?php

namespace Modules\Catalog\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Catalog\Http\CatalogAccess;
use Symfony\Component\HttpFoundation\Response;

final class CatalogAdminAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        app(CatalogAccess::class)->web($request->is('admin/catalog/categories') ? 'categories' : 'products');

        return $next($request);
    }
}
