<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Modules\Users\Application\Services\ManageUsers;
use Modules\Users\Contracts\UserAdministration;
use Tests\TestCase;

class UserCompositionTest extends TestCase
{
    public function test_service_contract_and_non_interactive_provisioning_are_safe_without_database_access(): void
    {
        $this->assertInstanceOf(ManageUsers::class, app(UserAdministration::class));
        $this->assertSame(1, Artisan::call('users:provision-admin', ['--no-interaction' => true]));
        $this->assertStringContainsString('Se requiere una consola interactiva', Artisan::output());
    }
}
