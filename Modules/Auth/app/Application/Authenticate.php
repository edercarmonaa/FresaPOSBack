<?php

namespace Modules\Auth\Application;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Auth\Contracts\Authenticator;
use Modules\Auth\Domain\InvalidCredentials;
use Modules\Auth\Infrastructure\Models\AccessToken;
use Modules\Core\Contracts\TransactionManager;
use Modules\Users\Contracts\IdentityAccess;
use Modules\Users\Contracts\UserSnapshot;

final class Authenticate implements Authenticator
{
    public function __construct(private readonly IdentityAccess $identities, private readonly SessionRegistry $sessions, private readonly TransactionManager $transactions) {}

    private function verify(Request $request, string $username, #[\SensitiveParameter] string $password): UserSnapshot
    {
        abort_if(! app()->environment('local', 'testing') && ! $request->isSecure(), 400);
        $key = 'auth-login:'.hash('sha256', $request->ip().'|'.strtolower(trim($username)));
        abort_if(RateLimiter::tooManyAttempts($key, (int) config('auth_access.login_attempts_per_minute')), 429);
        RateLimiter::hit($key, 60);
        $user = $this->identities->verify($username, $password);
        if (! $user) {
            throw new InvalidCredentials;
        }
        RateLimiter::clear($key);

        return $user;
    }

    public function loginWeb(Request $request, string $username, #[\SensitiveParameter] string $password): UserSnapshot
    {
        $user = $this->verify($request, $username, $password);
        $this->transactions->run(function () use ($request, $user): void {
            $this->sessions->retireWebContext($request);
            $this->sessions->assertCapacity($user->id, 'web');
            if (! Auth::guard('web')->loginUsingId($user->id, false)) {
                throw new InvalidCredentials;
            }
            $request->session()->regenerate(true);
            $request->session()->regenerateToken();
            $this->sessions->createWeb($request, $user);
        });

        return $user;
    }

    public function loginApi(Request $request, string $username, #[\SensitiveParameter] string $password, string $deviceName): array
    {
        $user = $this->verify($request, $username, $password);

        return $this->transactions->run(function () use ($user, $deviceName): array {
            $this->sessions->assertCapacity($user->id, 'api');

            return $this->issueToken($user, $deviceName);
        });
    }

    /** @return array<string,mixed> */
    private function issueToken(UserSnapshot $user, string $name): array
    {
        $native = Auth::guard('web')->getProvider()->retrieveById($user->id);
        if (! $native || ! method_exists($native, 'createToken')) {
            throw new InvalidCredentials;
        }
        $expires = now()->addMinutes(max(1, (int) config('auth_access.api_token_minutes')));
        $token = $native->createToken($name, [$user->mustChangePassword ? 'password:change' : 'access'], $expires);
        if (! $token->accessToken instanceof AccessToken) {
            throw new \LogicException('Se requiere el modelo Sanctum configurado.');
        }
        $this->sessions->createApi($user, $token->accessToken);

        return ['token' => $token->plainTextToken, 'token_type' => 'Bearer', 'expires_at' => $expires->toISOString(), 'user' => $user];
    }

    public function logoutWeb(Request $request): void
    {
        $record = $this->sessions->web($request, false);
        $this->transactions->run(fn () => $this->sessions->revoke($record));
        $this->clearWeb($request);
    }

    public function clearWeb(Request $request): void
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    public function logoutApi(Request $request): void
    {
        $record = $this->sessions->api($request);
        $this->transactions->run(fn () => $this->sessions->revoke($record));
    }

    public function changePassword(Request $request, #[\SensitiveParameter] string $current, #[\SensitiveParameter] string $next, bool $api): array
    {
        $id = $api ? (string) $request->user()->getAuthIdentifier() : (string) Auth::guard('web')->id();

        return $this->transactions->run(function () use ($request, $current, $next, $api, $id): array {
            $user = $this->identities->changePassword($id, $current, $next);
            $this->sessions->revokeAll($id);
            if ($api) {
                return $this->issueToken($user, 'password-change');
            }
            Auth::guard('web')->loginUsingId($id, false);
            $request->session()->regenerate(true);
            $request->session()->regenerateToken();
            $this->sessions->createWeb($request, $user);

            return ['user' => $user];
        });
    }
}
