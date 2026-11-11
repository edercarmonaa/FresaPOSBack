<?php

namespace Modules\Core\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class ApiRequestContext
{
    public static function isApi(Request $request): bool
    {
        return $request->is('api', 'api/*');
    }

    public static function handlesErrors(Request $request): bool
    {
        if (self::isApi($request)) {
            return true;
        }
        if ($request->is('livewire', 'livewire/*', 'livewire-*/*') || $request->headers->has('X-Livewire')) {
            return false;
        }

        return $request->expectsJson();
    }

    public static function requestId(Request $request): string
    {
        $stored = $request->attributes->get('_core_request_id');
        if (self::valid($stored)) {
            return $stored;
        }
        $incoming = self::isApi($request) ? $request->header('X-Request-Id') : null;
        $id = self::valid($incoming) ? $incoming : strtolower((string) Str::ulid());
        $request->attributes->set('_core_request_id', $id);

        return $id;
    }

    private static function valid(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $value) === 1;
    }
}
