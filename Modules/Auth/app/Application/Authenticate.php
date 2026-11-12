<?php

namespace Modules\Auth\Application;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Auth\Contracts\Authenticator;
use Modules\Auth\Domain\InvalidCredentials;
use Modules\Auth\Infrastructure\Models\AccessToken;
use Modules\Core\Contracts\SecurityEvent;
use Modules\Core\Contracts\TransactionManager;
use Modules\Users\Contracts\IdentityAccess;
use Modules\Users\Contracts\UserSnapshot;

final class Authenticate implements Authenticator
{
    public function __construct(private readonly IdentityAccess $identities, private readonly SessionRegistry $sessions, private readonly TransactionManager $transactions, private readonly AttemptGuard $attempts) {}

    private function verify(Request $request, string $username, #[\SensitiveParameter] string $password): UserSnapshot
    {
        abort_if(! app()->environment('local', 'testing') && ! $request->isSecure(), 400);
        $key = $this->attempts->key('login', $username, (string) $request->ip());

        return $this->attempts->run($key, function () use ($key, $username, $password): UserSnapshot {
            $this->attempts->check($key);
            $user = $this->identities->verify($username, $password);
            if (! $user) {
                $this->attempts->failed($key, 'password');
                throw new InvalidCredentials;
            }
            $this->attempts->clear($key);

            return $user;
        });
    }

    public function loginWeb(Request $request, string $username, #[\SensitiveParameter] string $password): UserSnapshot
    {
        $user = $this->verify($request, $username, $password);
        $this->transactions->run(function () use ($request, $user, $username, $password): void {
            $this->sessions->lock();
            $this->sessions->retireWebContext($request);
            $this->sessions->assertCapacity($user->id, 'web');
            $user = $this->identities->verify($username, $password) ?? throw new InvalidCredentials;
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

        return $this->transactions->run(function () use ($user, $deviceName, $username, $password): array {
            $this->sessions->assertCapacity($user->id, 'api');
            $user = $this->identities->verify($username, $password) ?? throw new InvalidCredentials;

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
        $this->transactions->run(function () use ($record): void {
            $this->sessions->revoke($record, $record->user_id);
            event(new SecurityEvent('logout', $record->user_id, $record->user_id));
        });
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
        $this->transactions->run(function () use ($record): void {
            $this->sessions->revoke($record, $record->user_id);
            event(new SecurityEvent('logout', $record->user_id, $record->user_id));
        });
    }

    public function changePassword(Request $request, #[\SensitiveParameter] string $current, #[\SensitiveParameter] string $next, bool $api): array
    {
        $id = $api ? (string) $request->user()->getAuthIdentifier() : (string) Auth::guard('web')->id();

        return $this->transactions->run(function () use ($request, $current, $next, $api, $id): array {
            $user = $this->identities->changePassword($id, $current, $next);
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
