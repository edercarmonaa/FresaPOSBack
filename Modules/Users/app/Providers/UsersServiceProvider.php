<?php

namespace Modules\Users\Providers;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Core\Contracts\ApiCode;
use Modules\Core\Contracts\ApiExceptionMappings;
use Modules\Users\Application\Ports\SecurityStore;
use Modules\Users\Application\Services\ManageUsers;
use Modules\Users\Application\Services\UserIdentityAccess;
use Modules\Users\Console\ProvisionAdministrator;
use Modules\Users\Contracts\IdentityAccess;
use Modules\Users\Contracts\UserAdministration;
use Modules\Users\Domain\SecurityConflict;
use Modules\Users\Infrastructure\Persistence\ActiveUserProvider;
use Modules\Users\Infrastructure\Persistence\EloquentSecurityStore;
use Modules\Users\Infrastructure\Persistence\Models\User;
use Modules\Users\Policies\UserPolicy;
use Nwidart\Modules\Support\ModuleServiceProvider;

class UsersServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Users';

    protected string $nameLower = 'users';

    /** @var array<class-string<ServiceProvider>> */
    protected array $providers = [
        RouteServiceProvider::class,
    ];

    protected array $commands = [ProvisionAdministrator::class];

    public function register(): void
    {
        parent::register();
        $this->app->bind(SecurityStore::class, EloquentSecurityStore::class);
        $this->app->bind(UserAdministration::class, ManageUsers::class);
        $this->app->bind(IdentityAccess::class, UserIdentityAccess::class);
    }

    public function boot(): void
    {
        parent::boot();
        Auth::provider('active-users', fn ($app, array $config) => new ActiveUserProvider($app->make('hash'), $config['model']));
        Relation::morphMap(['users.user' => User::class]);
        Gate::policy(User::class, UserPolicy::class);
        // Denegación general por estado; nunca un bypass por nombre de rol.
        Gate::before(fn ($user) => $user instanceof User && (! $user->is_active || $user->trashed()) ? false : null);
        $this->app->make(ApiExceptionMappings::class)->register(SecurityConflict::class, ApiCode::CONFLICT);
    }
}
