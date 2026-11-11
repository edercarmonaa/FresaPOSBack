<?php

namespace Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Core\Http\ApiRequestContext;
use Modules\Core\Http\ApiResponseFactory;
use Symfony\Component\HttpFoundation\Response;

final class AssignApiRequestId
{
    public function __construct(private readonly ApiResponseFactory $responses) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! ApiRequestContext::isApi($request)) {
            return $next($request);
        }
        $id = ApiRequestContext::requestId($request);
        $previousContext = Log::sharedContext();
        Log::shareContext(['request_id' => $id]);
        try {
            $response = $this->responses->normalize($request, $next($request));
            $response->headers->set('X-Request-Id', $id);

            return $response;
        } finally {
            Log::withoutContext(['request_id']);
            Log::flushSharedContext();
            Log::shareContext($previousContext);
        }
    }
}
