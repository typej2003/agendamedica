<?php

namespace App\Services;

use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * Gestión de cuentas de acceso (administradores y médicos). Es el ÚNICO lugar que escribe claves, roles y
 * bloqueos: el dashboard, el comando `cuentas:admin` y `POST /app/cambiar-password` pasan por acá.
 *
 * Por qué centralizado: la clave de un médico vive en `users.password` Y en `medicos.password` (legado). Si
 * cada pantalla escribiera una sola de las dos quedarían dos claves válidas a la vez. Acá `users` manda y
 * `medicos` se espeja siempre.
 *
 * Modelo (el que ya existía): `users` = cuentas de acceso, con roles Spatie (`Root`, `Administrador`,
 * `Medico`…); `medicos` = los doctores, unidos por `medicos.user_id`. Un médico puede ser también
 * administrador: una cuenta con varios roles.
 */
class CuentaService
{
    public const LARGO_MINIMO_CLAVE = 8;

    /** Sin caracteres que se confunden al dictarlos (0/O, 1/l/I). */
    private const ALFABETO_CLAVE = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';

    /** Clave temporal aleatoria, legible. */
    public function claveTemporal(int $largo = 10): string
    {
        $clave = '';
        $max = strlen(self::ALFABETO_CLAVE) - 1;
        for ($i = 0; $i < $largo; $i++) {
            $clave .= self::ALFABETO_CLAVE[random_int(0, $max)];
        }

        return $clave;
    }

    /* ------------------------------------------------------------------ */
    /* Alta                                                                */
    /* ------------------------------------------------------------------ */

    /**
     * @return array{user: User, clave: string} la clave en claro SOLO se devuelve acá (para mostrarla una vez).
     */
    public function crearAdministrador(string $nombre, string $email, ?string $clave = null): array
    {
        $email = $this->normalizarCorreo($email);
        $this->exigirCorreoLibre($email);
        $clave = $this->claveParaAlta($clave);

        $user = DB::transaction(function () use ($nombre, $email, $clave) {
            $user = User::create([
                'name'                 => trim($nombre),
                'email'                => $email,
                'password'             => Hash::make($clave),
                'must_change_password' => true,
                'is_active'            => true,
            ]);
            $user->assignRole($this->rol('Administrador'));

            return $user;
        });

        return ['user' => $user, 'clave' => $clave];
    }

    /**
     * Crea la ficha de médico y su cuenta de acceso.
     *
     * @param  array{name:string, lastname:string, email:string, reg_medico:string, phone?:?string, license_number?:?string}  $datos
     * @return array{user: User, medico: Medico, clave: string}
     */
    public function crearMedico(array $datos, ?string $clave = null): array
    {
        $email = $this->normalizarCorreo($datos['email']);
        $regMedico = trim((string) $datos['reg_medico']);

        $this->exigirCorreoLibre($email);
        $this->exigirRegMedicoLibre($regMedico);
        $clave = $this->claveParaAlta($clave);

        return DB::transaction(function () use ($datos, $email, $regMedico, $clave) {
            $hash = Hash::make($clave);

            $user = User::create([
                'name'                 => trim($datos['name'] . ' ' . $datos['lastname']),
                'email'                => $email,
                'password'             => $hash,
                'reg_medico'           => $regMedico,
                'must_change_password' => true,
                'is_active'            => true,
            ]);
            $user->assignRole($this->rol('Medico'));

            $medico = Medico::create([
                'user_id'        => $user->id,
                'name'           => trim($datos['name']),
                'lastname'       => trim($datos['lastname']),
                'email'          => $email,
                'password'       => $hash,
                'phone'          => $datos['phone'] ?? null,
                'license_number' => $datos['license_number'] ?? null,
                'reg_medico'     => $regMedico,
                'is_active'      => true,
            ]);
            MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

            return ['user' => $user, 'medico' => $medico, 'clave' => $clave];
        });
    }

    /**
     * Cuenta de acceso para un médico que ya existe en `medicos` pero no puede entrar (p. ej. lo creó el
     * panel viejo, que no pide clave). Si ya tiene cuenta, no hace nada y lo avisa.
     *
     * @return array{user: User, clave: string}
     */
    public function darAccesoAMedico(Medico $medico, ?string $clave = null): array
    {
        if ($medico->user_id && User::whereKey($medico->user_id)->exists()) {
            throw ValidationException::withMessages(['medico' => 'Este médico ya tiene una cuenta de acceso.']);
        }
        if (! $medico->email) {
            throw ValidationException::withMessages(['email' => 'El médico no tiene correo: se necesita para entrar.']);
        }

        $email = $this->normalizarCorreo($medico->email);
        $this->exigirCorreoLibre($email);
        $clave = $this->claveParaAlta($clave);

        $user = DB::transaction(function () use ($medico, $email, $clave) {
            $hash = Hash::make($clave);
            $user = User::create([
                'name'                 => trim($medico->name . ' ' . $medico->lastname),
                'email'                => $email,
                'password'             => $hash,
                'reg_medico'           => $medico->reg_medico,
                'must_change_password' => true,
                'is_active'            => (bool) ($medico->is_active ?? true),
            ]);
            $user->assignRole($this->rol('Medico'));
            $medico->forceFill(['user_id' => $user->id, 'password' => $hash])->save();

            return $user;
        });

        return ['user' => $user, 'clave' => $clave];
    }

    /* ------------------------------------------------------------------ */
    /* Claves                                                              */
    /* ------------------------------------------------------------------ */

    /**
     * Olvidó la clave: se le pone una temporal y se le obliga a cambiarla (igual que el primer inicio).
     * Cierra todas sus sesiones. Devuelve la clave en claro, para mostrarla una sola vez.
     */
    public function resetearClave(User $user, ?string $clave = null): string
    {
        $clave = $this->claveParaAlta($clave);

        DB::transaction(function () use ($user, $clave) {
            $this->escribirClave($user, $clave, temporal: true);
            $user->tokens()->delete();
        });

        return $clave;
    }

    /**
     * La persona eligió su clave. Apaga la marca de clave temporal. Con `$conservarToken` se mantiene la
     * sesión desde la que cambió la clave y se cierran las demás.
     */
    public function cambiarClave(User $user, string $nueva, ?int $conservarToken = null): void
    {
        $this->exigirClaveValida($nueva);

        DB::transaction(function () use ($user, $nueva, $conservarToken) {
            $this->escribirClave($user, $nueva, temporal: false);

            $tokens = $user->tokens();
            if ($conservarToken !== null) {
                $tokens->where('id', '!=', $conservarToken);
            }
            $tokens->delete();
        });
    }

    /** `users` manda; `medicos.password` se espeja (legado). */
    private function escribirClave(User $user, string $clave, bool $temporal): void
    {
        $hash = Hash::make($clave);

        $user->forceFill(['password' => $hash, 'must_change_password' => $temporal])->save();

        Medico::where('user_id', $user->id)->update(['password' => $hash]);
    }

    /* ------------------------------------------------------------------ */
    /* Bloqueo y roles                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * Bloquea el acceso al app y al panel (no corta el sync del escritorio: eso es otra credencial).
     */
    public function bloquear(User $user, string $motivo = '', ?User $actor = null): void
    {
        if ($actor && $actor->is($user)) {
            throw ValidationException::withMessages(['cuenta' => 'No puedes bloquear tu propia cuenta.']);
        }
        $this->exigirOtroAdministradorActivo($user, 'bloquear');

        DB::transaction(function () use ($user, $motivo) {
            $user->forceFill(['is_active' => false, 'blocked_reason' => trim($motivo) !== '' ? trim($motivo) : null])->save();
            Medico::where('user_id', $user->id)->update(['is_active' => false]);
            $user->tokens()->delete();
        });
    }

    public function desbloquear(User $user): void
    {
        DB::transaction(function () use ($user) {
            $user->forceFill(['is_active' => true, 'blocked_reason' => null])->save();
            Medico::where('user_id', $user->id)->update(['is_active' => true]);
        });
    }

    public function hacerAdministrador(User $user): void
    {
        $user->assignRole($this->rol('Administrador'));
    }

    public function quitarAdministrador(User $user, ?User $actor = null): void
    {
        if ($actor && $actor->is($user)) {
            throw ValidationException::withMessages(['cuenta' => 'No puedes quitarte a ti mismo el rol de administrador.']);
        }
        $this->exigirOtroAdministradorActivo($user, 'quitar el rol de administrador a');

        $user->removeRole('Administrador');
    }

    /** Nunca puede quedar el sistema sin un administrador activo. */
    private function exigirOtroAdministradorActivo(User $user, string $accion): void
    {
        if (! $user->esAdministrador()) {
            return;
        }

        $otros = User::administradores()->where('is_active', true)->where('id', '!=', $user->id)->count();
        if ($otros === 0) {
            throw ValidationException::withMessages([
                'cuenta' => "No se puede {$accion} el último administrador activo.",
            ]);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Validaciones                                                        */
    /* ------------------------------------------------------------------ */

    public function exigirClaveValida(string $clave): void
    {
        if (mb_strlen($clave) < self::LARGO_MINIMO_CLAVE) {
            throw ValidationException::withMessages([
                'password' => 'La contraseña debe tener al menos ' . self::LARGO_MINIMO_CLAVE . ' caracteres.',
            ]);
        }
    }

    private function claveParaAlta(?string $clave): string
    {
        $clave = $clave !== null && trim($clave) !== '' ? $clave : $this->claveTemporal();
        $this->exigirClaveValida($clave);

        return $clave;
    }

    private function normalizarCorreo(string $email): string
    {
        $email = mb_strtolower(trim($email));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['email' => 'El correo no es válido.']);
        }

        return $email;
    }

    private function exigirCorreoLibre(string $email): void
    {
        if (User::where('email', $email)->exists()) {
            throw ValidationException::withMessages(['email' => 'Ya existe una cuenta con ese correo.']);
        }
    }

    private function exigirRegMedicoLibre(string $regMedico): void
    {
        if ($regMedico === '') {
            throw ValidationException::withMessages(['reg_medico' => 'Falta el registro del médico (reg_medico).']);
        }
        if (Medico::where('reg_medico', $regMedico)->exists() || MedicoRegistro::where('reg_medico', $regMedico)->exists()) {
            throw ValidationException::withMessages(['reg_medico' => 'Ya existe un médico con ese reg_medico.']);
        }
    }

    private function rol(string $nombre): Role
    {
        return Role::firstOrCreate(['name' => $nombre, 'guard_name' => 'web']);
    }
}
