<?php

namespace Modules\Users\Console;

use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;
use Modules\Users\Application\BootstrapAdministrator;
use Modules\Users\Domain\SecurityConflict;

final class ProvisionAdministrator extends Command
{
    protected $signature = 'users:provision-admin';

    protected $description = 'Aprovisiona explícitamente el primer administrador, sin credenciales predeterminadas.';

    public function handle(BootstrapAdministrator $bootstrap): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Se requiere una consola interactiva. No se aceptan credenciales mediante argumentos.');

            return self::FAILURE;
        }
        $name = $this->ask('Nombre completo');
        $username = $this->ask('Nombre de usuario');
        $password = $this->secret('Contraseña', false);
        $confirmation = $this->secret('Confirma la contraseña', false);
        if (! is_string($name) || ! is_string($username) || ! is_string($password) || $password !== $confirmation) {
            $this->error('Datos incompletos o confirmación incorrecta.');

            return self::FAILURE;
        }
        try {
            $bootstrap->create($name, $username, $password);
        } catch (ValidationException $exception) {
            $this->error('Revisa nombre, usuario y política de contraseña. No se creó la cuenta.');

            return self::FAILURE;
        } catch (SecurityConflict $exception) {
            $this->error('El aprovisionamiento inicial no está disponible. No se modificaron cuentas.');

            return self::FAILURE;
        }
        $this->info('Administrador inicial creado. Inicia sesión y cambia la contraseña para continuar.');

        return self::SUCCESS;
    }
}
