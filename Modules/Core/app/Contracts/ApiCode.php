<?php

namespace Modules\Core\Contracts;

enum ApiCode: string
{
    case OPERATION_SUCCESS = 'OPERATION_SUCCESS';
    case BAD_REQUEST = 'BAD_REQUEST';
    case VALIDATION_ERROR = 'VALIDATION_ERROR';
    case UNAUTHENTICATED = 'UNAUTHENTICATED';
    case FORBIDDEN = 'FORBIDDEN';
    case NOT_FOUND = 'NOT_FOUND';
    case METHOD_NOT_ALLOWED = 'METHOD_NOT_ALLOWED';
    case CONFLICT = 'CONFLICT';
    case TOO_MANY_REQUESTS = 'TOO_MANY_REQUESTS';
    case INTERNAL_ERROR = 'INTERNAL_ERROR';
    case SERVICE_UNAVAILABLE = 'SERVICE_UNAVAILABLE';
    case INVALID_CREDENTIALS = 'INVALID_CREDENTIALS';
    case SESSION_EXPIRED = 'SESSION_EXPIRED';
    case PIN_INVALID = 'PIN_INVALID';
    case PASSWORD_CHANGE_REQUIRED = 'PASSWORD_CHANGE_REQUIRED';

    public function status(): int
    {
        return match ($this) {
            self::OPERATION_SUCCESS => 200,
            self::BAD_REQUEST => 400,
            self::UNAUTHENTICATED => 401,
            self::INVALID_CREDENTIALS, self::SESSION_EXPIRED, self::PIN_INVALID => 401,
            self::PASSWORD_CHANGE_REQUIRED => 403,
            self::FORBIDDEN => 403,
            self::NOT_FOUND => 404,
            self::METHOD_NOT_ALLOWED => 405,
            self::CONFLICT => 409,
            self::VALIDATION_ERROR => 422,
            self::TOO_MANY_REQUESTS => 429,
            self::SERVICE_UNAVAILABLE => 503,
            self::INTERNAL_ERROR => 500,
        };
    }

    public static function forStatus(int $status): self
    {
        foreach (self::cases() as $code) {
            if ($code->status() === $status) {
                return $code;
            }
        }

        return $status >= 500 ? self::INTERNAL_ERROR : self::BAD_REQUEST;
    }
}
