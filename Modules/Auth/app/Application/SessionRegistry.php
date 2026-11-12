<?php

namespace Modules\Auth\Application;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Auth\Domain\ExpiredSession;
use Modules\Auth\Infrastructure\Models\AccessSession;
use Modules\Auth\Infrastructure\Models\AccessToken;
use Modules\Auth\Policies\SessionPolicy;
use Modules\Core\Contracts\SecurityEvent;
use Modules\Core\Contracts\TransactionManager;
use Modules\Users\Contracts\IdentityAccess;
use Modules\Users\Contracts\UserSnapshot;

final class SessionRegistry
{
    public function __construct(private readonly TransactionManager $transactions, private readonly IdentityAccess $identities, private readonly SessionPolicy $policy) {}

    public function assertCapacity(string $userId, string $channel): void
    {
        $this->lock();
        abort_unless($this->identities->inspect($userId), 401);
        $sessions = AccessSession::query()->where('user_id', $userId)->lockForUpdate()->get();
        $count = $sessions->filter(fn ($session) => $session->channel === $channel && ! $session->revoked_at && $session->expires_at->isFuture())->count();
        abort_if($count >= max(1, (int) config('auth_access.max_'.$channel.'_sessions')), 429, 'Alcanzaste el límite de sesiones. Cierra un acceso existente para continuar.');
    }

    public function createWeb(Request $request, UserSnapshot $user, ?string $terminalOwner = null, string $method = 'password'): AccessSession
    {
        $session = AccessSession::query()->create([
            'user_id' => $user->id, 'channel' => 'web', 'auth_method' => $method,
            'session_hash' => hash('sha256', $request->session()->getId()), 'terminal_owner_id' => $terminalOwner,
            'last_activity_at' => now(), 'expires_at' => now()->addMinutes(max(1, (int) config('auth_access.web_idle_minutes'))),
        ]);
        event(new SecurityEvent('login', $user->id, $user->id, recordId: $session->id));

        return $session;
    }

    public function retireWebContext(Request $request): void
    {
        AccessSession::query()->where('channel', 'web')->where('session_hash', hash('sha256', $request->session()->getId()))
            ->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }

    public function createApi(UserSnapshot $user, AccessToken $token): AccessSession
    {
        $session = AccessSession::query()->create([
            'user_id' => $user->id, 'channel' => 'api', 'auth_method' => 'password', 'token_id' => $token->getKey(),
            'last_activity_at' => now(), 'expires_at' => $token->expires_at,
        ]);
        event(new SecurityEvent('login', $user->id, $user->id, recordId: $session->id));

        return $session;
    }

    public function web(Request $request, bool $touch = true): AccessSession
    {
        $id = Auth::guard('web')->id();
        $session = AccessSession::query()->where('channel', 'web')->where('user_id', $id)
            ->where('session_hash', hash('sha256', $request->session()->getId()))->first();
        $this->validate($session);
        if ($touch) {
            AccessSession::query()->whereKey($session->id)->whereNull('revoked_at')
                ->update(['last_activity_at' => now(), 'expires_at' => now()->addMinutes(max(1, (int) config('auth_access.web_idle_minutes')))]);
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
        if ($session && $session->getAttribute('auth_method') === 'pin') {
            $identity = $this->identities->inspect($session->user_id);
            $owner = $session->terminal_owner_id ? $this->identities->inspect($session->terminal_owner_id) : null;
            if (! $identity || ! $owner || ! in_array('cash.open', $owner->permissions, true)
                || count(array_filter($identity->permissions, fn (string $permission) => str_starts_with($permission, 'users.') || str_starts_with($permission, 'roles.'))) > 0) {
                throw new ExpiredSession;
            }
        }
        if ($session && ! $session->revoked_at && ! $session->expires_at->isFuture()) {
            $this->transactions->run(function () use ($session): void {
                $this->lock();
                $session->refresh();
                if (! $session->revoked_at && ! $session->expires_at->isFuture()) {
                    $this->revoke($session);
                    event(new SecurityEvent('session.expired', subjectId: $session->user_id, recordId: $session->id));
                }
            });
        }
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

    public function revoke(AccessSession $session, ?string $actorId = null): void
    {
        $this->lock();
        $session->refresh();
        if ($session->revoked_at) {
            return;
        }
        $session->update(['revoked_at' => now()]);
        event(new SecurityEvent('session.revoked', actorId: $actorId, subjectId: $session->user_id, recordId: $session->id));
        if ($session->token_id) {
            AccessToken::query()->whereKey($session->token_id)->delete();
            event(new SecurityEvent('token.revoked', actorId: $actorId, subjectId: $session->user_id, recordId: $session->id));
        }
    }

    public function revokeAll(string $userId, ?string $actorId = null): void
    {
        $this->lock();
        foreach (AccessSession::query()->where('user_id', $userId)->whereNull('revoked_at')->get() as $session) {
            $this->revoke($session, $actorId);
        }
        AccessToken::query()->where('tokenable_type', 'users.user')->where('tokenable_id', $userId)->delete();
    }

    /** @return list<array<string,mixed>> */
    public function listOwn(string $actorId): array
    {
        return $this->listAuthorized($actorId, $actorId);
    }

    /** @return list<array<string,mixed>> */
    public function listAuthorized(string $actorId, string $userId): array
    {
        $this->authorize($actorId, $userId);

        return AccessSession::query()->with('accessToken')->where('user_id', $userId)->orderBy('revoked_at')->orderByDesc('expires_at')->limit(50)->get()->map(fn ($session) => [
            'id' => $session->getKey(), 'user_id' => $session->user_id, 'channel' => $session->channel, 'client_name' => $session->accessToken?->getAttribute('name'),
            'state' => $session->revoked_at ? 'revoked' : ($session->expires_at->isFuture() ? 'active' : 'expired'), 'created_at' => $session->created_at->toISOString(),
            'last_activity_at' => $session->last_activity_at->toISOString(), 'expires_at' => $session->expires_at->toISOString(),
            'revoked_at' => $session->revoked_at?->toISOString(),
        ])->all();
    }

    public function revokeAuthorized(string $actorId, string $recordId): void
    {
        $this->transactions->run(function () use ($actorId, $recordId): void {
            $this->lock();
            $record = AccessSession::query()->lockForUpdate()->findOrFail($recordId);
            $this->authorize($actorId, $record->user_id);
            $this->revoke($record, $actorId);
            event(new SecurityEvent('session.revocation_requested', $actorId, $record->user_id, recordId: $record->id));
        });
    }

    public function revokeUser(string $actorId, string $userId, ?string $exceptId = null): void
    {
        $this->transactions->run(function () use ($actorId, $userId, $exceptId): void {
            $this->lock();
            $this->authorize($actorId, $userId);
            $records = AccessSession::query()->where('user_id', $userId)->whereNull('revoked_at');
            if ($exceptId !== null) {
                $records->where('id', '!=', $exceptId);
            }
            foreach ($records->get() as $record) {
                $this->revoke($record, $actorId);
            }
            if ($exceptId === null) {
                AccessToken::query()->where('tokenable_type', 'users.user')->where('tokenable_id', $userId)->delete();
            }
            event(new SecurityEvent('sessions.revoked', $actorId, $userId));
        });
    }

    private function authorize(string $actorId, string $userId): void
    {
        $actor = $this->identities->inspect($actorId);
        abort_unless($actor && $this->policy->manage($actor, $userId), 403);
    }

    public function lock(): void
    {
        $this->identities->lock();
    }
}
