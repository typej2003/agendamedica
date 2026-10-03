<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\CuentaService;
use Illuminate\Console\Command;

/**
 * Convierte una cuenta existente en administradora. Es el arranque del dashboard: el primer administrador
 * no se puede crear desde el dashboard (todavía no hay quien entre), así que se hace por consola.
 *
 *   php artisan cuentas:admin carlos@gmail.com
 *   php artisan cuentas:admin carlos@gmail.com --sin-cambio-de-clave   (no obliga a cambiar la clave)
 *
 * Idempotente. No toca el `tipo` de la cuenta: un médico sigue siendo médico y además administrador (entra
 * como médico y ve el botón "Modo Administrador"). Por defecto obliga a cambiar la clave en el próximo
 * inicio de sesión, porque las cuentas de semilla traen una clave conocida.
 */
class CuentasAdminCommand extends Command
{
    protected $signature = 'cuentas:admin
        {email : correo de una cuenta que ya existe en `users`}
        {--sin-cambio-de-clave : no marcar la clave como temporal}';

    protected $description = 'Da el rol Administrador a una cuenta existente (arranque del dashboard de administración)';

    public function handle(CuentaService $cuentas): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("No existe ninguna cuenta con el correo {$email}. Créala primero (por ejemplo desde el panel de médicos).");

            return self::FAILURE;
        }

        $yaEra = $user->esAdministrador();
        $cuentas->hacerAdministrador($user);

        if (! $this->option('sin-cambio-de-clave') && ! $user->must_change_password) {
            $user->forceFill(['must_change_password' => true])->save();
            $this->warn('Se le pedirá cambiar la clave en su próximo inicio de sesión.');
        }

        $this->info($yaEra
            ? "{$email} ya era administrador."
            : "{$email} ahora es administrador (tipo de cuenta: " . ($user->tipo ?? 'sin definir') . ').');

        return self::SUCCESS;
    }
}
