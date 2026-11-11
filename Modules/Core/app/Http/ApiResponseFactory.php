<?php

namespace Modules\Core\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Modules\Core\Contracts\ApiCode;
use Modules\Core\Contracts\ApiResponder;
use Symfony\Component\HttpFoundation\Response;

final class ApiResponseFactory implements ApiResponder
{
    public function success(Request $request, mixed $data, array $meta = [], int $status = 200): JsonResponse
    {
        if ($status < 200 || $status >= 300 || $status === 204) {
            throw new InvalidArgumentException('Una respuesta JSON de éxito requiere un estado 2xx con cuerpo.');
        }

        return $this->response($request, [
            'success' => true,
            'code' => ApiCode::OPERATION_SUCCESS->value,
            'message' => __('core::api.'.ApiCode::OPERATION_SUCCESS->value),
            'data' => $data,
            'meta' => array_replace($meta, ['request_id' => ApiRequestContext::requestId($request)]),
        ], $status);
    }

    public function error(Request $request, ApiCode $code, array $errors = [], ?int $status = null, array $headers = []): JsonResponse
    {
        $status ??= $code->status();
        if ($status < 400 || $status > 599 || $code->status() < 400) {
            throw new InvalidArgumentException('Una respuesta de error requiere un estado y código de error.');
        }
        $payload = [
            'success' => false,
            'code' => $code->value,
            'message' => __('core::api.'.$code->value),
            'meta' => ['request_id' => ApiRequestContext::requestId($request)],
        ];
        if ($errors !== []) {
            $payload['errors'] = $errors;
        }

        return $this->response($request, $payload, $status, $this->safeHeaders($headers));
    }

    public function normalize(Request $request, Response $response): Response
    {
        if ($response instanceof ApiJsonResponse || $response->getStatusCode() === 204 || $response->getStatusCode() === 304) {
            return $response;
        }
        $status = $response->getStatusCode();
        if ($status >= 400) {
            if ($status >= 500) {
                Log::error('API error response.', ['request_id' => ApiRequestContext::requestId($request), 'status' => $status]);
            }

            return $this->error($request, ApiCode::forStatus($status), status: $status, headers: $response->headers->all());
        }
        if ($status >= 200 && $status < 300) {
            $data = $response instanceof JsonResponse ? $response->getData(true) : $response->getContent();
            $normalized = $this->success($request, $data, status: $status);
            $normalized->headers->add($this->safeHeaders($response->headers->all()));

            return $normalized;
        }

        return $response;
    }

    /** @param array<string, string|list<string>> $headers
     * @return array<string, string|list<string>>
     */
    public function safeHeaders(array $headers): array
    {
        return array_filter($headers, fn (string $key): bool => in_array(strtolower($key), [
            'allow', 'retry-after', 'www-authenticate', 'x-ratelimit-limit', 'x-ratelimit-remaining', 'x-ratelimit-reset',
        ], true), ARRAY_FILTER_USE_KEY);
    }

    /** @param array<string, mixed> $payload
     * @param  array<string, string|list<string>>  $headers
     */
    private function response(Request $request, array $payload, int $status, array $headers = []): JsonResponse
    {
        if (ApiRequestContext::isApi($request)) {
            $headers['X-Request-Id'] = ApiRequestContext::requestId($request);
        }

        return new ApiJsonResponse($payload, $status, $headers);
    }
}
