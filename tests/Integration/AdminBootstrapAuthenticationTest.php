<?php

namespace Tests\Integration;

use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Modules\Users\Application\BootstrapAdministrator;
use Modules\Users\Domain\SecurityConflict;
use Modules\Users\Infrastructure\Persistence\Models\Role;
use Modules\Users\Infrastructure\Persistence\Models\User;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Question\Question;
use Tests\Support\AuthSqlServerTestCase;

class AdminBootstrapAuthenticationTest extends AuthSqlServerTestCase
{
    public function test_existing_interactive_command_provisions_only_test_database_without_printing_password(): void
    {
        $command = Artisan::all()['users:provision-admin'];
        $input = new ArrayInput(['command' => 'users:provision-admin']);
        $input->setInteractive(true);
        $buffer = new BufferedOutput;
        // Sustituir solo el dispositivo de entrada: hiddeninput.exe ignora los streams en Windows.
        $output = new class($input, $buffer) extends OutputStyle
        {
            private array $answers = ['Console Admin', 'console.admin', 'ConsoleInitial!123', 'ConsoleInitial!123'];

            public array $questions = [];

            public function askQuestion(Question $question): mixed
            {
                $this->questions[] = $question;

                return array_shift($this->answers);
            }
        };
        $this->assertSame(0, $command->run($input, $output));
        $this->assertStringNotContainsString('ConsoleInitial!123', $buffer->fetch());
        foreach ([2, 3] as $index) {
            $this->assertTrue($output->questions[$index]->isHidden());
            $this->assertFalse($output->questions[$index]->isHiddenFallback());
        }
        $user = User::query()->where('username', 'console.admin')->firstOrFail();
        $this->assertTrue($user->hasRole('admin'));
        $this->assertTrue($user->must_change_password);
        $this->assertSame(1, User::query()->count());
    }

    public function test_initial_admin_requires_seeded_permissions_and_valid_password_without_overwriting_accounts(): void
    {
        $role = Role::findByName('admin', 'web');
        $role->revokePermissionTo('users.create');
        try {
            app(BootstrapAdministrator::class)->create('First Admin', 'first.admin', 'InitialAdmin!123');
            $this->fail('Debe exigir permisos inicializados.');
        } catch (SecurityConflict) {
            $this->assertSame(0, User::query()->count());
        }
        $role->givePermissionTo('users.create');
        try {
            app(BootstrapAdministrator::class)->create('First Admin', 'first.admin', 'weak');
            $this->fail('Debe exigir una contraseña segura.');
        } catch (ValidationException) {
            $this->assertSame(0, User::query()->count());
        }
        $id = app(BootstrapAdministrator::class)->create('First Admin', 'first.admin', 'InitialAdmin!123');
        $user = User::query()->findOrFail($id);
        $this->assertTrue($user->hasRole('admin'));
        $this->assertTrue($user->must_change_password);
        $this->assertTrue(Hash::check('InitialAdmin!123', $user->password));
        $this->expectException(SecurityConflict::class);
        app(BootstrapAdministrator::class)->create('Another Admin', 'another.admin', 'OtherAdmin!123');
    }
}
