<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Modules\Auth\Http\SensitiveErrors;
use Modules\Core\Http\ApiExceptionHandler;
use Modules\Core\Http\ApiRequestContext;
use Modules\Core\Http\Middleware\AssignApiRequestId;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignApiRequestId::class);
        $middleware->redirectGuestsTo(fn (Request $request) => ApiRequestContext::isApi($request) ? null : route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['password', 'password_confirmation', 'current_password', 'pin', 'pin_confirmation', 'token']);
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => ApiRequestContext::isApi($request) || $request->expectsJson());
        $exceptions->report(function (Throwable $exception) {
            if (app(ApiExceptionHandler::class)->report($exception, request())) {
                return false;
            }

            return app(SensitiveErrors::class)->report($exception, request()) ? false : null;
        });
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request): Response {
            $response = app(ApiExceptionHandler::class)->respond($response, $exception, $request);

            return ApiRequestContext::handlesErrors($request) ? $response : app(SensitiveErrors::class)->respond($response, $exception, $request);
        });
    })->create();
