<?php

namespace Modules\Core\Http;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Modules\Core\Contracts\ApiCode;
use Modules\Core\Contracts\ApiExceptionMappings;
use Modules\Core\Contracts\ExceptionDiagnostic;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;
use WeakMap;

final class ApiExceptionHandler
{
    /** @var WeakMap<Throwable, bool> */
    private WeakMap $reported;

    public function __construct(private readonly ApiExceptionMappings $mappings, private readonly ApiResponseFactory $responses)
    {
        $this->reported = new WeakMap;
    }

    public function report(Throwable $exception, Request $request): bool
    {
        if (! ApiRequestContext::handlesErrors($request)) {
            return false;
        }
        if ($this->classify($exception)[1] >= 500 && ! isset($this->reported[$exception])) {
            Log::error('API request failed.', [
                'request_id' => ApiRequestContext::requestId($request),
                ...ExceptionDiagnostic::context($exception),
            ]);
            $this->reported[$exception] = true;
        }

        return true;
    }

    public function respond(Response $response, Throwable $exception, Request $request): Response
    {
        if (! ApiRequestContext::handlesErrors($request)) {
            return $response;
        }
        $this->report($exception, $request);
        [$code, $status] = $this->classify($exception);
        $errors = $exception instanceof ValidationException ? $exception->errors() : [];

        return $this->responses->error($request, $code, $errors, $status, $response->headers->all());
    }

    /** @return array{ApiCode, int} */
    private function classify(Throwable $exception): array
    {
        if ($exception instanceof QueryException) {
            return [ApiCode::INTERNAL_ERROR, 500];
        }
        if ($exception instanceof ValidationException) {
            return [ApiCode::VALIDATION_ERROR, 422];
        }
        if ($exception instanceof AuthenticationException) {
            return [ApiCode::UNAUTHENTICATED, 401];
        }
        if ($exception instanceof AuthorizationException) {
            $status = $exception->status() ?? 403;

            return [ApiCode::forStatus($status), $status];
        }
        if ($exception instanceof HttpResponseException) {
            $status = $exception->getResponse()->getStatusCode();

            return $status >= 400 ? [ApiCode::forStatus($status), $status] : [ApiCode::INTERNAL_ERROR, 500];
        }
        if ($exception instanceof HttpExceptionInterface) {
            return [ApiCode::forStatus($exception->getStatusCode()), $exception->getStatusCode()];
        }
        if ($exception instanceof BadRequestException) {
            return [ApiCode::BAD_REQUEST, 400];
        }
        $code = $this->mappings->codeFor($exception) ?? ApiCode::INTERNAL_ERROR;

        return [$code, $code->status()];
    }
}
