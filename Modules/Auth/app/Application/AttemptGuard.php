<?php

namespace Modules\Auth\Application;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Core\Contracts\SecurityEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

final class AttemptGuard
{
    public function key(string $kind, string $identity, string $origin): string
    {
        return 'auth-'.$kind.':'.hash('sha256', strtolower(trim($identity)).'|'.$origin);
    }

    public function check(string $key, ?string $actorId = null): void
    {
        if (RateLimiter::tooManyAttempts($key.':lock', 1)) {
            throw new TooManyRequestsHttpException(RateLimiter::availableIn($key.':lock'), 'Demasiados intentos. Espera el período indicado e inténtalo de nuevo.');
        }
        if (Cache::pull($key.':blocked')) {
            RateLimiter::clear($key);
            event(new SecurityEvent('authentication.unlocked', $actorId));
        }
    }

    public function failed(string $key, string $type, ?string $actorId = null, ?string $subjectId = null): void
    {
        event(new SecurityEvent($type.'.failed', $actorId, $subjectId, result: 'failure'));
        $attempts = RateLimiter::hit($key, max(1, (int) config('auth_access.lock_seconds')));
        if ($attempts === $this->limit()) {
            RateLimiter::hit($key.':lock', max(1, (int) config('auth_access.lock_seconds')));
            Cache::put($key.':blocked', true, now()->addDay());
            event(new SecurityEvent($type.'.locked', $actorId, $subjectId, result: 'blocked'));
        }
    }

    public function clear(string $key): void
    {
        RateLimiter::clear($key);
        RateLimiter::clear($key.':lock');
        Cache::forget($key.':blocked');
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    public function run(string $key, Closure $operation): mixed
    {
        return Cache::lock($key.':mutex', 15)->block(5, $operation);
    }

    private function limit(): int
    {
        return max(1, (int) config('auth_access.failed_attempts'));
    }
}
