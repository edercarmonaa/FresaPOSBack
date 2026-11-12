<?php

namespace Modules\Auth\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Core\Contracts\ExceptionDiagnostic;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;
use WeakMap;

final class SensitiveErrors
{
    /** @var WeakMap<Throwable,bool> */
    private WeakMap $reported;

    public function __construct()
    {
        $this->reported = new WeakMap;
    }

    private function handles(Request $request): bool
    {
        if ($request->is('login', 'logout', 'account', 'admin', 'auth/*', 'admin/catalog/*')) {
            return true;
        }
        $components = $request->input('components', []);
        if (! $request->headers->has('X-Livewire') || ! is_array($components)) {
            return false;
        }
        foreach ($components as $component) {
            if (! is_array($component) || ! is_string($component['snapshot'] ?? null)) {
                continue;
            }
            $snapshot = json_decode($component['snapshot'], true);
            if (is_array($snapshot) && in_array($snapshot['memo']['name'] ?? null, ['auth.login', 'auth.sessions', 'catalog.categories', 'catalog.products'], true)) {
                return true;
            }
        }

        return false;
    }

    public function report(Throwable $exception, Request $request): bool
    {
        if (! $this->handles($request)) {
            return false;
        }
        $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;
        if ($status >= 500 && ! isset($this->reported[$exception])) {
            Log::error('Authentication request failed.', ExceptionDiagnostic::context($exception));
            $this->reported[$exception] = true;
        }

        return true;
    }

    public function respond(Response $response, Throwable $exception, Request $request): Response
    {
        if ($response->getStatusCode() < 500 || ! $this->handles($request)) {
            return $response;
        }
        $this->report($exception, $request);
        $headers = ['Cache-Control' => 'no-store, private'];
        if ($response->headers->has('Retry-After')) {
            $headers['Retry-After'] = $response->headers->get('Retry-After');
        }
        $message = __('auth::access.server_error');

        return $request->expectsJson()
            ? response()->json(['message' => $message], $response->getStatusCode(), $headers)
            : response($message, $response->getStatusCode(), $headers + ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
