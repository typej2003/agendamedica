<?php

namespace App\Services;

use App\Models\Medico;
use App\Models\Paciente;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * **El único camino del login web**: correo y contraseña, sin elegir el tipo de usuario.
 *
 * La misma persona puede estar en tres tablas y la cuenta que usa el `Auth` de Laravel es siempre un
 * `User`:
 *
 *  - `users`: la administración, la secretaría y las cuentas de médicos que ya se crearon (es la tabla
 *    que manda; `CuentaService` espeja ahí la clave de `medicos`).
 *  - `medicos`: el médico del legado que todavía no tiene fila en `users`. Al entrar se le crea la cuenta
 *    y se le asigna el rol `Medico`.
 *  - `pacientes`: el paciente del legado, con el mismo tratamiento (rol `Paciente`).
 *
 * Se prueban en ese orden y gana la primera clave que coincide. El orden y la creación de la cuenta son
 * los mismos que ya usaba `LoginAppController` para el móvil; ese endpoint no se tocó (su JSON y su
 * comportamiento no cambian).
 */
class AutenticacionPorCorreo
{
    /**
     * Devuelve el `User` que corresponde a esas credenciales, o `null` si no coinciden con nadie.
     *
     * No distingue entre "ese correo no existe" y "la clave está mal": el controlador muestra un solo
     * mensaje para no revelar qué correos están registrados. Una cuenta bloqueada tampoco entra por acá:
     * `EnsureAccountActive` la corta en la siguiente petición, con su propio mensaje.
     */
    public function autenticar(string $email, string $password): ?User
    {
        $email = mb_strtolower(trim($email));

        if ($email === '' || $password === '') {
            return null;
        }

        if ($user = $this->cuentaDeUsuario($email, $password)) {
            return $user;
        }

        if ($user = $this->cuentaDeMedico($email, $password)) {
            return $user;
        }

        return $this->cuentaDePaciente($email, $password);
    }

    /** 1. La tabla `users`: administración, secretaría y las cuentas de médicos ya creadas. */
    private function cuentaDeUsuario(string $email, string $password): ?User
    {
        $user = $this->porCorreo(User::query(), $email);

        return $user && $this->claveCoincide($password, $user->password) ? $user : null;
    }

    /** 2. El médico del legado (`medicos`), creándole la cuenta de `users` la primera vez. */
    private function cuentaDeMedico(string $email, string $password): ?User
    {
        $medico = $this->porCorreo(Medico::query(), $email);

        if (! $medico || ! $this->claveCoincide($password, $medico->password)) {
            return null;
        }

        // Un médico desactivado no entra por el camino del legado: su cuenta de `users` no existe o no
        // se actualizó, así que el bloqueo hay que respetarlo acá (el middleware sólo ve `users`). La
        // columna no tiene cast en el modelo, así que en SQLite llega como 0 y no como `false`.
        if (! $medico->is_active) {
            return null;
        }

        $user = $this->porCorreo(User::query(), $medico->email);

        if (! $user) {
            $user = User::create([
                'name'     => trim(($medico->name ?? '') . ' ' . ($medico->lastname ?? '')) ?: 'Médico',
                'email'    => $medico->email,
                'password' => $medico->password, // el mismo hash que ya está en `medicos`
            ]);

            $medico->user_id = $user->id;
            $medico->save();
        }

        if (! $user->hasRole('Medico')) {
            $user->assignRole('Medico');
        }

        return $user;
    }

    /** 3. El paciente del legado (`pacientes`), con el mismo tratamiento que el médico. */
    private function cuentaDePaciente(string $email, string $password): ?User
    {
        $paciente = $this->porCorreo(Paciente::query(), $email);

        if (! $paciente || ! $this->claveCoincide($password, $paciente->password)) {
            return null;
        }

        $user = $this->porCorreo(User::query(), $paciente->email);

        if (! $user) {
            $user = User::create([
                'name'     => trim(($paciente->nombres ?? '') . ' ' . ($paciente->apellidos ?? '')) ?: 'Paciente',
                'email'    => $paciente->email,
                'password' => $paciente->password,
            ]);

            $paciente->user_id = $user->id;
            $paciente->save();
        }

        if (! $user->hasRole('Paciente')) {
            $user->assignRole('Paciente');
        }

        return $user;
    }

    /**
     * Busca por correo sin importar mayúsculas ni minúsculas: la base de desarrollo es SQLite (compara
     * distinguiendo mayúsculas) y la de producción es MySQL (no), así que la regla se escribe una vez.
     */
    private function porCorreo($consulta, string $email)
    {
        return $consulta->whereRaw('LOWER(email) = ?', [$email])->first();
    }

    private function claveCoincide(string $password, ?string $hash): bool
    {
        return is_string($hash) && $hash !== '' && Hash::check($password, $hash);
    }
}
