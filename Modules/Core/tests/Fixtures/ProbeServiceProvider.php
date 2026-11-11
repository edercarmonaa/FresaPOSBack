<?php

namespace Modules\Core\Tests\Fixtures;

use Modules\Core\Providers\CoreServiceProvider;

final class ProbeServiceProvider extends CoreServiceProvider
{
    public function register(): void
    {
        parent::register();

        $this->app->bind(ProbeContract::class, Probe::class);
    }
}
