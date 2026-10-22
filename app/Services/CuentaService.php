<?php

namespace App\Services;

use App\Actions\Catalogos\CargarCatalogosInicialesAlMedico;
use App\Actions\Medicos\SiguienteRegMedico;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Specialty;
use App\Models\Subspecialty;
use App\Models\User;
use Illuminate\Support\Collection;
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

    /**
     * Habilidad del token Sanctum emitido en un login con clave temporal: prueba que quien lo usa acaba de
     * escribir esa clave, así que `POST /api/app/cambiar-password` no se la vuelve a pedir. Se le quita al
     * token que se conserva al cambiar la clave (`cambiarClave`), para que un reseteo posterior no lo reavive.
     */
    public const HABILIDAD_CLAVE_TEMPORAL = 'clave-temporal';

    /** Marca de sesión web equivalente: el login con clave temporal acaba de verificarla. */
    public const SESION_CLAVE_TEMPORAL = 'cuenta.clave_temporal_verificada';

    /** Sin caracteres que se confunden al dictarlos (0/O, 1/l/I). */
    private const ALFABETO_CLAVE = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';

    /**
     * Los valores de `medicos.formato_reportes` (Fase 2 del cambio de `reg_medico`): con qué membrete se
     * imprimen los reportes del centro. La **firma** es siempre la del doctor que atendió; esto decide el
     * membrete. Quien lo usa para imprimir es `MembreteDelMedico::deLaImpresion()` (Fase 5).
     */
    public const FORMATOS_DE_REPORTE = [Medico::FORMATO_DEL_CENTRO, Medico::FORMATO_DEL_MEDICO];

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
        if ($regMedico !== '') {
            $this->exigirRegMedicoLibre($regMedico);
        }
        $clave = $this->claveParaAlta($clave);

        return DB::transaction(function () use ($datos, $email, $regMedico, $clave) {
            // Vacío: se genera `med_NNNNN` dentro de esta transacción (con bloqueo).
            $regMedico = $regMedico !== '' ? $regMedico : app(SiguienteRegMedico::class)->ejecutar();
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
     * Crea la ficha de un **centro médico** (Fase 2 del cambio de `reg_medico`): una fila de `medicos` con
     * `es_centro = 1` y su propio `reg_medico` (p. ej. `centro_001`).
     *
     * Un centro **no es un doctor y no tiene cuenta de acceso**: `user_id` queda nulo (si algún día lo
     * tuviera, no atiende: el contexto de un centro no ofrece la consulta). Es el **tenant** donde trabajan
     * uno o varios doctores: los consultorios, los pacientes, las historias y la agenda son suyos, y los
     * doctores se vinculan con `asignarRegistro()` —el pivote `medico_registros`—, que ya no copia
     * pacientes.
     *
     * El correo es opcional: el centro no inicia sesión. Si viene, tiene que estar libre, igual que el
     * `reg_medico` (es único).
     *
     * @param  array{name:string, lastname:string, reg_medico:string, prefix?:?string, email?:?string, phone?:?string}  $datos
     */
    public function crearCentro(array $datos): Medico
    {
        $nombre = trim((string) ($datos['name'] ?? ''));
        $apellido = trim((string) ($datos['lastname'] ?? ''));
        $regMedico = trim((string) ($datos['reg_medico'] ?? ''));

        if ($nombre === '' || $apellido === '') {
            throw ValidationException::withMessages(['name' => 'El nombre y el apellido del centro son obligatorios.']);
        }

        if ($regMedico !== '') {
            $this->exigirRegMedicoLibre($regMedico);
        }

        $email = isset($datos['email']) && trim((string) $datos['email']) !== ''
            ? $this->normalizarCorreo((string) $datos['email'])
            : null;

        if ($email !== null) {
            $this->exigirCorreoLibre($email);
        }

        return DB::transaction(function () use ($datos, $nombre, $apellido, $regMedico, $email) {
            $regMedico = $regMedico !== '' ? $regMedico : app(SiguienteRegMedico::class)->ejecutar();

            return Medico::create([
                'user_id'    => null,
                'name'       => $nombre,
                'lastname'   => $apellido,
                'prefix'     => $datos['prefix'] ?? null,
                'email'      => $email,
                'phone'      => $this->vacioANull($datos['phone'] ?? null),
                'reg_medico' => $regMedico,
                'is_active'  => true,
                'es_centro'  => true,
            ]);
        });
    }

    /**
     * Marca o desmarca una ficha como **centro médico** y le guarda el **formato de sus reportes**
     * (`centro`/`medico`).
     *
     * El formato decide el **membrete, el logo y el pie** con los que el centro imprime los reportes de sus
     * doctores (`MembreteDelMedico::deLaImpresion()`, Fase 5): `centro` usa las filas del propio centro —las
     * que se configuran en `/clinica/formato?reg_medico=<centro>`, que abre el `Root` desde el panel— y
     * `medico` las del doctor, como en un consultorio personal. La **firma y el sello son siempre los del
     * doctor que atendió**, elija lo que elija el centro.
     *
     * No se valida contra un catálogo cerrado de fichas existentes: convertir un médico en centro es
     * exactamente lo que pide el panel.
     *
     * @param  string|null  $formato  `centro`, `medico` o `null` (sin elegir)
     */
    public function configurarCentro(Medico $medico, bool $esCentro, ?string $formato = null): Medico
    {
        $formato = $this->vacioANull($formato);

        if ($formato !== null && ! in_array($formato, self::FORMATOS_DE_REPORTE, true)) {
            throw ValidationException::withMessages([
                'formato_reportes' => 'El formato de los reportes es «centro» o «medico».',
            ]);
        }

        $medico->forceFill([
            'es_centro'        => $esCentro,
            'formato_reportes' => $formato,
        ])->save();

        return $medico->fresh();
    }

    /**
     * Cuenta de acceso para un médico que ya existe en `medicos` pero no puede entrar (p. ej. lo creó el
     * panel viejo, que no pide clave). Si ya tiene cuenta, no hace nada y lo avisa.
     *
     * Un **centro médico** no tiene cuenta: un centro no inicia sesión (y si algún día la tuviera, no
     * atiende). Lo atiende un doctor vinculado.
     *
     * @return array{user: User, clave: string}
     */
    public function darAccesoAMedico(Medico $medico, ?string $clave = null): array
    {
        if ($medico->es_centro) {
            throw ValidationException::withMessages([
                'medico' => 'Un centro médico no tiene cuenta de acceso: lo atienden los doctores vinculados.',
            ]);
        }
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
                $conservado = $user->tokens()->whereKey($conservarToken)->first();
                if ($conservado && in_array(self::HABILIDAD_CLAVE_TEMPORAL, $conservado->abilities ?? [], true)) {
                    $conservado->forceFill(['abilities' => array_values(array_diff(
                        $conservado->abilities, [self::HABILIDAD_CLAVE_TEMPORAL]
                    ))])->save();
                }
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
     * El correo puede quedar **vacío** desde la Fase 2 del cambio de `reg_medico`: un **centro médico** no
     * inicia sesión y no lo necesita. En ese caso no se toca el de la cuenta (que sí es obligatorio para
     * entrar), y uno que la tenga se queda con el suyo.
     *
     * @param  array{name:string, lastname:string, email?:?string, prefix?:?string, phone?:?string, license_number?:?string}  $datos
     */
    public function actualizarMedico(Medico $medico, array $datos): Medico
    {
        $nombre = trim((string) ($datos['name'] ?? ''));
        $apellido = trim((string) ($datos['lastname'] ?? ''));
        if ($nombre === '' || $apellido === '') {
            throw ValidationException::withMessages(['name' => 'El nombre y el apellido son obligatorios.']);
        }
        $correo = trim((string) ($datos['email'] ?? ''));
        $email = $correo === '' ? null : $this->normalizarCorreo($correo);
        if ($email !== null) {
            $this->exigirCorreoLibre($email, $medico->user_id ? User::find($medico->user_id) : null, $medico);
        }

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
                // El correo de la cuenta es con el que entra: si la ficha no tiene, se conserva el suyo.
                $cambios = ['name' => trim("{$nombre} {$apellido}")];
                if ($email !== null) {
                    $cambios['email'] = $email;
                }
                User::whereKey($medico->user_id)->update($cambios);
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
     * Qué `reg_medico` ve un médico. El sync lee la agenda, las consultas y los récipes por `medico_registros` y
     * la lista de pacientes por el **`reg_medico` del vínculo** (`medico_pacientes`), así que dar acceso a un
     * registro le muestra sus pacientes sin copiar una sola fila: **el vínculo es del consultorio** (Fase 1 del
     * cambio de `reg_medico`, migración del 2026-10-20).
     *
     * El `reg_medico` propio (`medicos.reg_medico`) no se puede quitar. Escrituras: lo que el médico CREE cuelga de su
     * propio `reg_medico`; lo que EDITE de un registro ajeno (confirmar o cobrar una cita) queda en los datos de ese
     * registro. Y el servicio contratado se mide por el mejor estado entre sus registros (`ServicioService::mejorEstado`).
     *
     * @return string[] el propio primero, luego los asignados
     */
    public function registrosDe(Medico $medico): array
    {
        // La regla vive en el modelo (`Medico::registrosDeTrabajo`): es la misma lista que usan el contexto
        // de la web clínica y la agenda para resolver la `clave` de `evolucion` del doctor.
        return $medico->registrosDeTrabajo();
    }

    /**
     * Los `reg_medico` que se le pueden asignar: los de otros médicos que existen (no se inventan a mano, para que un
     * error de tipeo no abra acceso a un registro vacío), menos los que ya tiene.
     *
     * Desde la Fase 2 del cambio de `reg_medico` la lista es la de **los centros donde el doctor puede
     * trabajar**, así que se marcan los que son centro (`medicos.es_centro`): el panel tiene que dejar ver
     * qué está asociando.
     *
     * @return array<string, string> reg_medico => "Nombre (reg_medico)"
     */
    public function registrosAsignables(Medico $medico): array
    {
        return Medico::whereNotNull('reg_medico')
            ->whereNotIn('reg_medico', $this->registrosDe($medico))
            ->orderBy('name')->orderBy('lastname')
            ->get(['name', 'lastname', 'reg_medico', 'es_centro'])
            ->mapWithKeys(fn (Medico $m) => [
                $m->reg_medico => trim($m->name . ' ' . $m->lastname)
                    . " ({$m->reg_medico})"
                    . ($m->es_centro ? ' · centro médico' : ''),
            ])
            ->all();
    }

    /**
     * Le da acceso al médico a los datos de otro consultorio. **Sólo agrega la fila de `medico_registros`.**
     *
     * Antes copiaba además cada paciente del registro a `medico_pacientes` con el `medico_id` del médico, porque
     * el vínculo era uno por médico y la lista se leía por `medico_id`. Desde que el vínculo es **uno por
     * paciente y consultorio** (`UNIQUE (reg_medico, paciente_id)`) esa copia no sólo era inútil: chocaba con el
     * único o duplicaba al paciente. La visibilidad ahora sale del `reg_medico` —el mismo que usa la agenda—, así
     * que alcanza con la fila de `medico_registros`.
     */
    public function asignarRegistro(Medico $medico, string $regMedico): void
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

        DB::transaction(function () use ($medico, $regMedico) {
            MedicoRegistro::create(['medico_id' => $medico->id, 'reg_medico' => $regMedico]);
        });
    }

    /**
     * Quita el acceso a un registro asignado. **No borra ningún dato**: sólo la fila de `medico_registros`.
     *
     * Antes borraba además las filas de `medico_pacientes` de ese médico y ese registro. Ya no: esas filas son
     * **del consultorio** (el vínculo paciente ↔ tenant) y no del médico que tenía el acceso, así que borrarlas
     * le quitaría el paciente al consultorio entero — y, con el único nuevo, dejaba al paciente sin vínculo.
     */
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
        });
    }

    /* ------------------------------------------------------------------ */
    /* La secretaría del consultorio (WEB-8.5)                             */
    /* ------------------------------------------------------------------ */

    /**
     * Las cuentas **solo-secretaría** vinculadas a alguno de estos `reg_medico` (`medico_registros.user_id`). Es la lista que
     * ve un médico en Configuración > Usuarios: una cuenta con otro rol además (médico, administrador) nunca aparece, así
     * que no hay forma de tocarla desde ahí.
     *
     * @param  array<int,string>  $registros  los `reg_medico` del médico
     * @return Collection<int,User>  con `vinculos` = los `reg_medico` de la cuenta (todos, no sólo los del médico)
     */
    public function secretariasDe(array $registros): Collection
    {
        if ($registros === []) {
            return collect();
        }

        $ids = MedicoRegistro::whereIn('reg_medico', $registros)->whereNotNull('user_id')->pluck('user_id')->unique();

        return User::whereIn('id', $ids)
            ->get()
            ->filter(fn (User $user) => $this->esSoloSecretaria($user))
            ->each(function (User $user) {
                $user->setAttribute('vinculos', MedicoRegistro::where('user_id', $user->id)->pluck('reg_medico')->filter()->unique()->values()->all());
            })
            ->sortBy(fn (User $user) => mb_strtolower($user->name))
            ->values();
    }

    /** ¿La cuenta es una secretaría vinculada a alguno de estos registros (y nada más que secretaría)? */
    public function esSecretariaDe(User $user, array $registros): bool
    {
        return $registros !== []
            && $this->esSoloSecretaria($user)
            && MedicoRegistro::where('user_id', $user->id)->whereIn('reg_medico', $registros)->exists();
    }

    /**
     * ¿Trabaja también con registros que **no son de este médico**? Entonces la cuenta es compartida: este médico puede
     * quitarle SU acceso pero no tocar la cuenta (ni su clave, ni su bloqueo), porque lo que le haga lo sufre el otro consultorio
     * y, con una clave que él conoce, vería los datos del otro.
     */
    public function secretariaCompartida(User $user, array $registros): bool
    {
        return MedicoRegistro::where('user_id', $user->id)->whereNotIn('reg_medico', $registros)->exists();
    }

    /**
     * Da de alta a la secretaría de un consultorio: cuenta nueva con rol `Secretaria`, clave temporal (que debe cambiar al
     * entrar) y acceso a UN `reg_medico`. Un correo que ya tiene cuenta se rechaza: nunca se "adopta" la cuenta de otra
     * persona.
     *
     * @return array{user: User, clave: string} la clave en claro SOLO se devuelve acá (para mostrarla una vez)
     */
    public function crearSecretaria(string $nombre, string $email, string $regMedico, ?string $clave = null): array
    {
        $nombre = trim($nombre);
        if ($nombre === '') {
            throw ValidationException::withMessages(['name' => 'El nombre es obligatorio.']);
        }

        $regMedico = trim($regMedico);
        if ($regMedico === '' || ! (Medico::where('reg_medico', $regMedico)->exists() || MedicoRegistro::where('reg_medico', $regMedico)->exists())) {
            throw ValidationException::withMessages(['reg_medico' => 'Ese consultorio no existe.']);
        }

        $email = $this->normalizarCorreo($email);
        try {
            $this->exigirCorreoLibre($email);
        } catch (ValidationException $e) {
            // Nunca se adopta la cuenta de otra persona: el correo ya está en uso y se pide otro.
            throw ValidationException::withMessages([
                'email' => 'Ese correo ya tiene una cuenta (de otra persona o de otro consultorio) y no se puede reutilizar. Usa otro correo.',
            ]);
        }
        $clave = $this->claveParaAlta($clave);

        $user = DB::transaction(function () use ($nombre, $email, $clave, $regMedico) {
            $user = User::create([
                'name'                 => $nombre,
                'email'                => $email,
                'password'             => Hash::make($clave),
                'must_change_password' => true,
                'is_active'            => true,
            ]);
            $user->assignRole($this->rol('Secretaria'));
            MedicoRegistro::create(['user_id' => $user->id, 'reg_medico' => $regMedico]);

            return $user;
        });

        return ['user' => $user, 'clave' => $clave];
    }

    /** Clave temporal nueva para la secretaría (la misma regla que el panel). Una cuenta compartida no se toca. */
    public function restablecerClaveDeSecretaria(User $secretaria, array $registros): string
    {
        $this->exigirSecretariaPropia($secretaria, $registros, 'restablecer su clave');

        return $this->resetearClave($secretaria);
    }

    /** Bloquea la cuenta de la secretaría (la misma regla que el panel). Una cuenta compartida no se toca. */
    public function bloquearSecretaria(User $secretaria, array $registros, string $motivo = '', ?User $actor = null): void
    {
        $this->exigirSecretariaPropia($secretaria, $registros, 'bloquear su cuenta');
        $this->bloquear($secretaria, $motivo, $actor);
    }

    public function desbloquearSecretaria(User $secretaria, array $registros): void
    {
        $this->exigirSecretariaPropia($secretaria, $registros, 'desbloquear su cuenta');
        $this->desbloquear($secretaria);
    }

    /**
     * Le quita a la secretaría el acceso a los registros de ESTE médico. Nada se borra: si todavía trabaja con otro
     * consultorio la cuenta sigue viva para él; si no le queda ningún acceso, la cuenta queda inactiva (sin sesiones ni
     * tokens), lista para que un administrador la reutilice.
     *
     * @return array{activa: bool}  si la cuenta sigue activa porque conserva otros accesos
     */
    public function quitarAccesoDeSecretaria(User $secretaria, array $registros): array
    {
        if (! $this->esSecretariaDe($secretaria, $registros)) {
            throw ValidationException::withMessages(['cuenta' => 'Esa cuenta no es una secretaría de tu consultorio.']);
        }

        return DB::transaction(function () use ($secretaria, $registros) {
            MedicoRegistro::where('user_id', $secretaria->id)->whereIn('reg_medico', $registros)->delete();

            if (MedicoRegistro::where('user_id', $secretaria->id)->exists()) {
                return ['activa' => true];
            }

            $secretaria->forceFill(['is_active' => false, 'blocked_reason' => 'Sin acceso a ningún consultorio.'])->save();
            $secretaria->tokens()->delete();

            return ['activa' => false];
        });
    }

    /** Solo el rol `Secretaria` (ni médico, ni administrador): lo único que un médico puede gestionar. */
    private function esSoloSecretaria(User $user): bool
    {
        $roles = $user->getRoleNames();

        return $roles->count() === 1 && $roles->first() === 'Secretaria' && ! Medico::where('user_id', $user->id)->exists();
    }

    private function exigirSecretariaPropia(User $secretaria, array $registros, string $accion): void
    {
        if (! $this->esSecretariaDe($secretaria, $registros)) {
            throw ValidationException::withMessages(['cuenta' => 'Esa cuenta no es una secretaría de tu consultorio.']);
        }

        if ($this->secretariaCompartida($secretaria, $registros)) {
            throw ValidationException::withMessages([
                'cuenta' => "No puedes {$accion}: también trabaja con otro consultorio. Puedes quitarle tu acceso y pedirle a un administrador que gestione su cuenta.",
            ]);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Especialidades del médico                                           */
    /* ------------------------------------------------------------------ */

    /**
     * Deja al médico con EXACTAMENTE estas especialidades del catálogo (pivote `medico_specialty`).
     *
     * Es lo que decide con qué trabaja en `/clinica`: `ContextoTrabajo` arma el contexto con las
     * especialidades **activas** del médico y de ahí salen los módulos del menú (PLAN-WEB.md, R1/R2).
     * Ojo con el catálogo: acá se usa `specialties` —el de AppDDR—, **no** `especial`, que es el del
     * legado por médico (`doctores.codeespecial`).
     *
     * Se puede dejar sin ninguna (el pivote no lo impide y hay médicos del sync que nunca tuvieron): la
     * web clínica lo trata como "sin especialidad asignada" y no le muestra módulos. No se crean
     * especialidades desde acá: el catálogo lo siembran los manifiestos (`config/especialidades/*.php`
     * con `EspecialidadesYModulosSeeder`).
     *
     * @param  array<int|string>  $especialidadIds
     * @return array{asignadas: int, quitadas: int}  para el aviso de la pantalla
     */
    public function asignarEspecialidades(Medico $medico, array $especialidadIds): array
    {
        $ids = collect($especialidadIds)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        if ($desconocidas = $ids->diff(Specialty::whereIn('id', $ids)->pluck('id'))->all()) {
            throw ValidationException::withMessages([
                'especialidades' => 'Especialidad desconocida: ' . implode(', ', $desconocidas) . '.',
            ]);
        }

        $resultado = DB::transaction(function () use ($medico, $ids) {
            // `sync` deja el pivote con exactamente estos ids: agrega los que faltan y quita los que sobran.
            $cambios = $medico->specialties()->sync($ids->all());

            // **Los postgrados siguen a su especialidad** (2026-10-19): si se quita Pediatría, no tiene sentido
            // que el médico siga con «Nefrología pediátrica». Se quitan —y se informan— los que quedaron huérfanos.
            $huerfanos = $medico->subespecialidades()
                ->whereNotIn('specialty_id', $ids->all())
                ->pluck('subespecialidades.id')
                ->all();

            if ($huerfanos !== []) {
                $medico->subespecialidades()->detach($huerfanos);
            }

            return [
                'asignadas' => count($cambios['attached']),
                'quitadas'  => count($cambios['detached']),
                'postgradosQuitados' => count($huerfanos),
            ];
        });

        // **El médico nuevo no empieza en cero** (WEB-12.9): al sumarle una especialidad se le copia el catálogo inicial en los
        // catálogos que tiene vacíos. Va después de guardar: si la copia fallara, la especialidad igual queda asignada.
        $resultado['catalogoInicial'] = $resultado['asignadas'] > 0
            ? app(CargarCatalogosInicialesAlMedico::class)->ejecutar($medico->fresh())
            : null;

        return $resultado;
    }

    /**
     * Deja al médico con EXACTAMENTE estos **postgrados** (pivote `medico_subespecialidad`, 2026-10-19).
     *
     * Un postgrado cuelga de una especialidad: sólo se puede asignar el de una especialidad que el médico
     * **tenga**. Se ignoran (no se rechazan) los ids que no cumplen, porque el formulario manda todo junto y una
     * especialidad recién destildada todavía puede venir en la lista; lo que no se hace es guardar un postgrado
     * sin su especialidad.
     *
     * @param  array<int|string>  $subespecialidadIds
     * @return array{asignadas: int, quitadas: int}
     */
    public function asignarSubespecialidades(Medico $medico, array $subespecialidadIds): array
    {
        $ids = collect($subespecialidadIds)
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        $especialidadesDelMedico = $medico->specialties()->pluck('specialties.id')->all();

        $validas = $ids->isEmpty()
            ? collect()
            : Subspecialty::whereIn('id', $ids)
                ->whereIn('specialty_id', $especialidadesDelMedico)
                ->pluck('id');

        return DB::transaction(function () use ($medico, $validas) {
            $cambios = $medico->subespecialidades()->sync($validas->all());

            return [
                'asignadas' => count($cambios['attached']),
                'quitadas'  => count($cambios['detached']),
            ];
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
