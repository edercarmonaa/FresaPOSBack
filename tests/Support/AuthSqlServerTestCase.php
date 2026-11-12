<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Auth;
use Modules\Users\Database\Seeders\SecuritySeeder;
use Spatie\Permission\PermissionRegistrar;

abstract class AuthSqlServerTestCase extends SqlServerTestCase
{
    private array $authSchemas = [];

    private bool $ownsAuthSchema = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->setLocale('es');
        config(['hashing.bcrypt.rounds' => 4]);
        $this->authSchemas = [
            require base_path('Modules/Users/database/migrations/2026_11_11_000001_create_user_security_tables.php'),
            require base_path('Modules/Auth/database/migrations/2026_11_11_000002_create_auth_access_tables.php'),
            require base_path('Modules/Audit/database/migrations/2026_11_11_000003_create_security_events.php'),
        ];
        $this->database->transaction(function (): void {
            foreach ($this->authSchemas as $schema) {
                $schema->up();
            }
        });
        $this->ownsAuthSchema = true;
        app(SecuritySeeder::class)->run();
    }

    protected function tearDown(): void
    {
        try {
            $this->travelBack();
            Auth::forgetGuards();
            while ($this->database->transactionLevel() > 0) {
                $this->database->rollBack();
            }
            if ($this->ownsAuthSchema) {
                foreach (array_reverse($this->authSchemas) as $schema) {
                    $schema->down();
                }
            }
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        } finally {
            parent::tearDown();
        }
    }
}
