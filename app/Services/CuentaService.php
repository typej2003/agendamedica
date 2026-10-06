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
     * @param  array{name:string, lastname:string, email:string, reg_medico:string, prefix?:?string, phone?:?string, license_number?:?string}  $datos
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
                'prefix'         => $datos['prefix'] ?? null,
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
        // El correo ya figura en la ficha de este mismo médico: eso no cuenta como repetido.
        $this->exigirCorreoLibre($email, null, $medico);
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
    /* Edición de datos                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * Edita los datos de un médico: nombre, apellido, correo, teléfono y licencia. NO el `reg_medico`: es la
     * llave con la que están guardados todos sus datos clínicos en la nube, cambiarlo los dejaría huérfanos.
     * Si tiene cuenta de acceso, nombre y correo se actualizan también ahí (el correo es con el que inicia sesión).
     *
     * @param  array{name:string, lastname:string, email:string, prefix?:?string, phone?:?string, license_number?:?string}  $datos
     */
    public function actualizarMedico(Medico $medico, array $datos): Medico
    {
        $nombre = trim((string) ($datos['name'] ?? ''));
        $apellido = trim((string) ($datos['lastname'] ?? ''));
        if ($nombre === '' || $apellido === '') {
            throw ValidationException::withMessages(['name' => 'El nombre y el apellido son obligatorios.']);
        }
        $email = $this->normalizarCorreo((string) ($datos['email'] ?? ''));
        $this->exigirCorreoLibre($email, $medico->user_id ? User::find($medico->user_id) : null, $medico);

        return DB::transaction(function () use ($medico, $datos, $nombre, $apellido, $email) {
            $medico->forceFill([
                'name'           => $nombre,
                'lastname'       => $apellido,
                'email'          => $email,
                'phone'          => $this->vacioANull($datos['phone'] ?? null),
                'license_number' => $this->vacioANull($datos['license_number'] ?? null),
            ]);
            // El prefijo solo se toca si viene: quien llama sin él (otra pantalla) no debe borrarlo.
            if (array_key_exists('prefix', $datos)) {
                $medico->prefix = $datos['prefix'];
            }
            $medico->save();

            if ($medico->user_id) {
                User::whereKey($medico->user_id)->update(['name' => trim("{$nombre} {$apellido}"), 'email' => $email]);
            }

            return $medico->fresh();
        });
    }

    /**
     * Edita nombre y correo de una cuenta de acceso (administrador, o médico visto desde la lista de
     * administradores). Si la cuenta tiene ficha de médico, el correo se copia ahí (el login del legado lo usa).
     */
    public function actualizarUsuario(User $user, array $datos): User
    {
        $nombre = trim((string) ($datos['name'] ?? ''));
        if ($nombre === '') {
            throw ValidationException::withMessages(['name' => 'El nombre es obligatorio.']);
        }
        $email = $this->normalizarCorreo((string) ($datos['email'] ?? ''));
        $ficha = Medico::where('user_id', $user->id)->first();
        $this->exigirCorreoLibre($email, $user, $ficha);

        return DB::transaction(function () use ($user, $nombre, $email, $ficha) {
            $user->forceFill(['name' => $nombre, 'email' => $email])->save();
            if ($ficha) {
                $ficha->forceFill(['email' => $email])->save();
            }

            return $user->fresh();
        });
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

    /** Roles que se pueden asignar desde el panel. Se crean si faltan (en una base nueva solo existen los que ya se usaron). */
    public const ROLES_BASE = ['Root', 'Administrador', 'Medico', 'Secretaria', 'Paciente', 'Representante'];

    /** @return string[] */
    public function rolesDisponibles(): array
    {
        foreach (self::ROLES_BASE as $nombre) {
            $this->rol($nombre);
        }

        return Role::where('guard_name', 'web')->orderBy('name')->pluck('name')->all();
    }

    /**
     * Deja a la cuenta con EXACTAMENTE estos roles (agrega los nuevos y quita los que falten).
     *
     * Protecciones: solo un usuario Root puede dar o quitar el rol Root (un Administrador no se asciende solo);
     * nadie se quita a sí mismo el acceso de administración; y el sistema no puede quedar sin un administrador activo.
     *
     * @param  string[]  $roles
     */
    public function asignarRoles(User $user, array $roles, ?User $actor = null): void
    {
        $roles = collect($roles)->map(fn ($r) => trim((string) $r))->filter()->unique()->values();
        $validos = collect($this->rolesDisponibles());
        if ($desconocidos = $roles->diff($validos)->all()) {
            throw ValidationException::withMessages(['roles' => 'Rol desconocido: ' . implode(', ', $desconocidos) . '.']);
        }

        $actuales = $user->roles()->pluck('name');
        $cambiaRoot = $actuales->contains('Root') !== $roles->contains('Root');
        if ($cambiaRoot && ! ($actor && $actor->hasRole('Root'))) {
            throw ValidationException::withMessages(['roles' => 'Solo un usuario Root puede dar o quitar el rol Root.']);
        }

        $eraAdmin = $actuales->intersect(User::ROLES_ADMIN)->isNotEmpty();
        $seraAdmin = $roles->intersect(User::ROLES_ADMIN)->isNotEmpty();
        if ($eraAdmin && ! $seraAdmin) {
            if ($actor && $actor->is($user)) {
                throw ValidationException::withMessages(['roles' => 'No puedes quitarte a ti mismo el acceso de administración.']);
            }
            $this->exigirOtroAdministradorActivo($user, 'quitar el acceso de administración al');
        }

        $user->syncRoles($roles->all());
    }

    /* ------------------------------------------------------------------ */
    /* Registros de datos (reg_medico) de un médico                        */
    /* ------------------------------------------------------------------ */

    /**
     * Qué `reg_medico` ve un médico. El sync lee la agenda, las consultas y los récipes por `medico_registros` y la
     * lista de pacientes por `medico_pacientes`, así que dar acceso a un registro hace las dos cosas: suma la fila de
     * `medico_registros` y vincula al médico con los pacientes de ese registro (y quitarlo, lo contrario).
     *
     * El `reg_medico` propio (`medicos.reg_medico`) no se puede quitar. Escrituras: lo que el médico CREE cuelga de su
     * propio `reg_medico`; lo que EDITE de un registro ajeno (confirmar o cobrar una cita) queda en los datos de ese
     * registro. Y el servicio contratado se mide por el mejor estado entre sus registros (`ServicioService::mejorEstado`).
     *
     * @return string[] el propio primero, luego los asignados
     */
    public function registrosDe(Medico $medico): array
    {
        return collect([$medico->reg_medico])
            ->merge(MedicoRegistro::where('medico_id', $medico->id)->orderBy('id')->pluck('reg_medico'))
            ->filter()->unique()->values()->all();
    }

    /**
     * Los `reg_medico` que se le pueden asignar: los de otros médicos que existen (no se inventan a mano, para que un
     * error de tipeo no abra acceso a un registro vacío), menos los que ya tiene.
     *
     * @return array<string, string> reg_medico => "Nombre (reg_medico)"
     */
    public function registrosAsignables(Medico $medico): array
    {
        return Medico::whereNotNull('reg_medico')
            ->whereNotIn('reg_medico', $this->registrosDe($medico))
            ->orderBy('name')->orderBy('lastname')
            ->get(['name', 'lastname', 'reg_medico'])
            ->mapWithKeys(fn (Medico $m) => [$m->reg_medico => trim($m->name . ' ' . $m->lastname) . " ({$m->reg_medico})"])
            ->all();
    }

    /** @return int cuántos pacientes se le vincularon */
    public function asignarRegistro(Medico $medico, string $regMedico): int
    {
        $regMedico = trim($regMedico);
        if ($regMedico === '') {
            throw ValidationException::withMessages(['regNuevo' => 'Elige el reg_medico a asignar.']);
        }
        if (! Medico::where('reg_medico', $regMedico)->exists()) {
            throw ValidationException::withMessages(['regNuevo' => 'Ese reg_medico no existe.']);
        }
        if (in_array($regMedico, $this->registrosDe($medico), true)) {
            throw ValidationException::withMessages(['regNuevo' => 'Este médico ya tiene acceso a ese reg_medico.']);
        }

        return DB::transaction(function () use ($medico, $regMedico) {
            MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);

            $yaTiene = DB::table('medico_pacientes')->where('medico_id', $medico->id)->pluck('paciente_id')->flip();
            $vinculados = 0;
            DB::table('medico_pacientes')->where('reg_medico', $regMedico)->where('medico_id', '!=', $medico->id)
                ->orderBy('id')->get(['paciente_id', 'numhistoria'])
                ->unique('paciente_id')
                ->reject(fn ($f) => $yaTiene->has($f->paciente_id))
                ->chunk(500)
                ->each(function ($lote) use ($medico, $regMedico, &$vinculados) {
                    DB::table('medico_pacientes')->insert($lote->map(fn ($f) => [
                        'medico_id'   => $medico->id,
                        'paciente_id' => $f->paciente_id,
                        'numhistoria' => $f->numhistoria,
                        'reg_medico'  => $regMedico,
                        'created_at'  => now(),
                        'updated_at'  => now(),
                    ])->all());
                    $vinculados += $lote->count();
                });

            return $vinculados;
        });
    }

    /** Quita el acceso a un registro asignado. No borra ningún dato: solo los vínculos de este médico. */
    public function quitarRegistro(Medico $medico, string $regMedico): void
    {
        $regMedico = trim($regMedico);
        if ($regMedico === (string) $medico->reg_medico) {
            throw ValidationException::withMessages(['regNuevo' => 'El reg_medico propio del médico no se puede quitar.']);
        }
        if (! MedicoRegistro::where('medico_id', $medico->id)->where('reg_medico', $regMedico)->exists()) {
            throw ValidationException::withMessages(['regNuevo' => 'Este médico no tiene acceso a ese reg_medico.']);
        }

        DB::transaction(function () use ($medico, $regMedico) {
            DB::table('medico_registros')->where('medico_id', $medico->id)->where('reg_medico', $regMedico)->delete();
            DB::table('medico_pacientes')->where('medico_id', $medico->id)->where('reg_medico', $regMedico)->delete();
        });
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

    /** El correo no puede estar en otra cuenta ni en otra ficha de médico (se ignoran las propias). */
    private function exigirCorreoLibre(string $email, ?User $propia = null, ?Medico $propiaFicha = null): void
    {
        $enUsers = User::where('email', $email)->when($propia, fn ($q) => $q->where('id', '!=', $propia->id))->exists();
        $enFichas = Medico::where('email', $email)->when($propiaFicha, fn ($q) => $q->where('id', '!=', $propiaFicha->id))->exists();

        if ($enUsers || $enFichas) {
            throw ValidationException::withMessages(['email' => 'Ya existe una cuenta con ese correo.']);
        }
    }

    private function vacioANull($valor): ?string
    {
        $valor = $valor === null ? '' : trim((string) $valor);

        return $valor === '' ? null : $valor;
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
