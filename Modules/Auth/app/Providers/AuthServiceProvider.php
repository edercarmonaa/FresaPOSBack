<?php

namespace Modules\Auth\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Modules\Auth\Application\Authenticate;
use Modules\Auth\Application\SessionRegistry;
use Modules\Auth\Contracts\Authenticator;
use Modules\Auth\Domain\ExpiredSession;
use Modules\Auth\Domain\InvalidCredentials;
use Modules\Auth\Domain\InvalidPin;
use Modules\Auth\Domain\PasswordChangeRequired;
use Modules\Auth\Http\Livewire\Login;
use Modules\Auth\Http\Livewire\Sessions;
use Modules\Auth\Http\Middleware\EnsureApiAccess;
use Modules\Auth\Http\Middleware\EnsureWebAccess;
use Modules\Auth\Http\Middleware\NoStore;
use Modules\Auth\Http\SensitiveErrors;
use Modules\Auth\Infrastructure\Models\AccessToken;
use Modules\Auth\Infrastructure\Models\EmployeePin;
use Modules\Core\Contracts\ApiCode;
use Modules\Core\Contracts\ApiExceptionMappings;
use Modules\Core\Contracts\TransactionManager;
use Modules\Users\Contracts\IdentityAccess;
use Modules\Users\Contracts\UserAccessChanged;
use Nwidart\Modules\Support\ModuleServiceProvider;

class AuthServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Auth';

    protected string $nameLower = 'auth';

    /** @var array<class-string<ServiceProvider>> */
    protected array $providers = [
        RouteServiceProvider::class,
    ];

    public function register(): void
    {
        parent::register();
        $this->app->bind(Authenticator::class, Authenticate::class);
        $this->app->singleton(SensitiveErrors::class);
    }

    public function boot(): void
    {
        parent::boot();
        // No capturar argumentos en excepciones: pueden contener claves, PIN o tokens.
        if (ini_get('zend.exception_ignore_args') !== '1' && ini_set('zend.exception_ignore_args', '1') === false) {
            throw new \LogicException('Se requiere zend.exception_ignore_args activado para proteger credenciales.');
        }
        $router = $this->app->make('router');
        $router->aliasMiddleware('auth.access.web', EnsureWebAccess::class);
        $router->aliasMiddleware('auth.access.api', EnsureApiAccess::class);
        $router->aliasMiddleware('auth.no-store', NoStore::class);
        Livewire::component('auth.login', Login::class);
        Livewire::component('auth.sessions', Sessions::class);
        Livewire::addPersistentMiddleware([EnsureWebAccess::class]);
        Sanctum::usePersonalAccessTokenModel(AccessToken::class);
        Sanctum::authenticateAccessTokensUsing(fn ($token, bool $valid) => $valid && $this->app->make(IdentityAccess::class)->inspect((string) $token->tokenable_id) !== null);
        config(['session.lifetime' => max(1, (int) config('auth_access.web_idle_minutes')), 'session.http_only' => true, 'session.same_site' => 'lax']);
        if (! $this->app->environment('local', 'testing')) {
            config(['session.secure' => true]);
        }
        RateLimiter::for('auth-security', fn (Request $request) => Limit::perMinute(5)->by(hash('sha256', (string) $request->user()?->getAuthIdentifier().'|'.$request->ip())));
        $mappings = $this->app->make(ApiExceptionMappings::class);
        $mappings->register(InvalidCredentials::class, ApiCode::INVALID_CREDENTIALS);
        $mappings->register(ExpiredSession::class, ApiCode::SESSION_EXPIRED);
        $mappings->register(InvalidPin::class, ApiCode::PIN_INVALID);
        $mappings->register(PasswordChangeRequired::class, ApiCode::PASSWORD_CHANGE_REQUIRED);
        Event::listen(UserAccessChanged::class, function ($event): void {
            // Users puede existir antes del esquema Auth. Una vez instalado, la invalidación es síncrona.
            if (Schema::hasTable('auth_access_sessions')) {
                $this->app->make(TransactionManager::class)->run(function () use ($event): void {
                    $this->app->make(SessionRegistry::class)->revokeAll($event->userId, $event->actorId);
                    if (in_array($event->reason, ['user.deactivated', 'user.deleted'], true)) {
                        EmployeePin::query()->whereKey($event->userId)->delete();
                        \Modules\Auth\Infrastructure\Models\AccessSession::query()->where('terminal_owner_id', $event->userId)->whereNull('revoked_at')->update(['revoked_at' => now()]);
                    }
                });
            }
        });
    }
}
