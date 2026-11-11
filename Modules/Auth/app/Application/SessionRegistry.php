<?php

namespace Modules\Auth\Application;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Auth\Domain\ExpiredSession;
use Modules\Auth\Infrastructure\Models\AccessSession;
use Modules\Auth\Infrastructure\Models\AccessToken;
use Modules\Core\Contracts\TransactionManager;
use Modules\Users\Contracts\IdentityAccess;
use Modules\Users\Contracts\UserSnapshot;

final class SessionRegistry
{
    public function __construct(private readonly TransactionManager $transactions, private readonly IdentityAccess $identities) {}

    public function assertCapacity(string $userId, string $channel): void
    {
        // Se llama dentro de la transacción del servicio; serializa la emisión por usuario.
        $sessions = AccessSession::query()->where('user_id', $userId)->lockForUpdate()->get();
        $count = $sessions->filter(fn ($session) => $session->channel === $channel && ! $session->revoked_at && $session->expires_at->isFuture())->count();
        abort_if($count >= max(1, (int) config('auth_access.max_'.$channel.'_sessions')), 429);
    }

    public function createWeb(Request $request, UserSnapshot $user, ?string $terminalOwner = null, string $method = 'password'): AccessSession
    {
        return AccessSession::query()->create([
            'user_id' => $user->id, 'channel' => 'web', 'auth_method' => $method,
            'session_hash' => hash('sha256', $request->session()->getId()), 'terminal_owner_id' => $terminalOwner,
            'last_activity_at' => now(), 'expires_at' => now()->addMinutes(max(1, (int) config('auth_access.web_idle_minutes'))),
        ]);
    }

    public function retireWebContext(Request $request): void
    {
        AccessSession::query()->where('channel', 'web')->where('session_hash', hash('sha256', $request->session()->getId()))
            ->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    public function createApi(UserSnapshot $user, AccessToken $token): AccessSession
    {
        return AccessSession::query()->create([
            'user_id' => $user->id, 'channel' => 'api', 'auth_method' => 'password', 'token_id' => $token->getKey(),
            'last_activity_at' => now(), 'expires_at' => $token->expires_at,
        ]);
    }

    public function web(Request $request, bool $touch = true): AccessSession
    {
        $id = Auth::guard('web')->id();
        $session = AccessSession::query()->where('channel', 'web')->where('user_id', $id)
            ->where('session_hash', hash('sha256', $request->session()->getId()))->first();
        $this->validate($session);
        if ($touch) {
            $session->update(['last_activity_at' => now(), 'expires_at' => now()->addMinutes(max(1, (int) config('auth_access.web_idle_minutes')))]);
        }

        return $session;
    }

    public function api(Request $request): AccessSession
    {
        $token = $this->token($request);
        $session = AccessSession::query()->where('channel', 'api')->where('token_id', $token->getKey())->where('user_id', $request->user()->getAuthIdentifier())->first();
        $this->validate($session);
        $session->update(['last_activity_at' => now()]);

        return $session;
    }

    private function validate(?AccessSession $session): void
    {
        if (! $session || $session->revoked_at || ! $session->expires_at->isFuture() || ! $this->identities->inspect($session->user_id)
            || ($session->terminal_owner_id && ! $this->identities->inspect($session->terminal_owner_id))) {
            throw new ExpiredSession;
        }
    }

    public function token(Request $request): AccessToken
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();
        if (! $token instanceof AccessToken || $token->tokenable_id !== $user->getAuthIdentifier()) {
            throw new ExpiredSession;
        }

        return $token;
    }

    public function revoke(AccessSession $session): void
    {
        $session->update(['revoked_at' => now()]);
        if ($session->token_id) {
            AccessToken::query()->whereKey($session->token_id)->delete();
        }
    }

    public function revokeAll(string $userId): void
    {
        foreach (AccessSession::query()->where('user_id', $userId)->whereNull('revoked_at')->get() as $session) {
            $this->revoke($session);
        }
        AccessToken::query()->where('tokenable_type', 'users.user')->where('tokenable_id', $userId)->delete();
    }

    /** @return list<array<string,mixed>> */
    public function listOwn(string $actorId): array
    {
        abort_unless($this->identities->inspect($actorId) !== null, 403);

        return AccessSession::query()->where('user_id', $actorId)->orderByDesc('id')->limit(50)->get()->map(fn ($session) => [
            'id' => $session->getKey(), 'channel' => $session->channel, 'created_at' => $session->created_at->toISOString(),
            'last_activity_at' => $session->last_activity_at->toISOString(), 'expires_at' => $session->expires_at->toISOString(),
            'revoked_at' => $session->revoked_at?->toISOString(),
        ])->all();
    }

    public function revokeAuthorized(string $actorId, string $recordId): void
    {
        $this->transactions->run(function () use ($actorId, $recordId): void {
            $actor = $this->identities->inspect($actorId);
            abort_unless($actor && ! $actor->mustChangePassword, 403);
            $record = AccessSession::query()->lockForUpdate()->findOrFail($recordId);
            abort_unless($record->user_id === $actorId || in_array('users.status', $actor->permissions, true), 403);
            $this->revoke($record);
        });
    }
}
