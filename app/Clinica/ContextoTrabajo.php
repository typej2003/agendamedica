<?php

namespace App\Clinica;

use App\Especialidades\ConfiguracionDeLaEspecialidad;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\Office;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Contexto de trabajo de la web clínica: **con qué datos** está operando el usuario.
 *
 * Un usuario puede tener acceso a varios `reg_medico` (el suyo, los centros donde trabaja y los que le
 * asignaron desde el panel) y cada uno puede tener más de una especialidad y varios consultorios.
 * Todo eso se elige una vez, se guarda en la sesión y **se revalida en cada request**: si le quitaron el
 * acceso, el contexto deja de ser válido y la web lo manda a elegirlo de nuevo.
 *
 * Desde la Fase 2 del cambio de `reg_medico` (2026-10-20) el `medico` del contexto es **el doctor
 * logueado** en todos sus registros —también en los centros donde trabaja—, y en la **secretaría** es la
 * fila del **centro** con las especialidades de sus doctores. Las dos reglas están en `acceso()`.
 *
 * La especialidad es lo que decide qué módulos se ven (PLAN-WEB.md, R1/R2): no hay `if` por especialidad.
 */
class ContextoTrabajo
{
    private const SESION = 'clinica.contexto';

    /** Roles del usuario, tal como los usa el catálogo de módulos. */
    public function roles(User $user): array
    {
        return $user->getRoleNames()->all();
    }

    /**
     * Todo lo que este usuario puede elegir: `reg_medico` => [medico, especialidades, sedes].
     *
     * **El `medico` de cada acceso no siempre es el dueño del `reg_medico`** (Fase 2 del cambio de
     * `reg_medico`, 2026-10-20):
     *
     *  - si el usuario tiene **ficha de médico** en ese registro, el médico es **él** —aunque el registro
     *    sea el de un centro donde trabaja—, con **sus** especialidades;
     *  - si no (la secretaría), el médico es la **fila del centro** (`Medico::where('reg_medico')`) y las
     *    especialidades que ofrece son la **unión de las de los doctores del centro**: el centro no tiene
     *    especialidades propias. En un registro que no es un centro (el caso heredado, una secretaría
     *    asignada al `reg_medico` de un doctor) la unión es la de ese doctor, que es lo que ya hacía.
     *
     * Los **consultorios** son los del tenant en los dos casos: es donde se atiende el consultorio, no
     * donde el doctor atendía en otra instalación.
     *
     * @return array<string, array{reg_medico: string, medico: Medico, es_centro: bool, especialidades: Collection, sedes: Collection}>
     */
    public function disponibles(User $user): array
    {
        $medicos = $this->medicosDelUsuario($user);

        $registros = MedicoRegistro::query()
            ->where(function ($consulta) use ($user, $medicos) {
                $consulta->where('user_id', $user->id)
                    ->orWhereIn('medico_id', $medicos->pluck('id')->all());
            })
            ->pluck('reg_medico')
            ->filter()
            ->all();

        // El `reg_medico` propio de la ficha, por si todavía no hay fila en el pivote.
        foreach ($medicos as $medico) {
            if ($medico->reg_medico) {
                $registros[] = $medico->reg_medico;
            }
        }

        $salida = [];
        foreach (array_values(array_unique($registros)) as $registro) {
            $acceso = $this->acceso($registro, $medicos);

            if ($acceso !== null) {
                $salida[$registro] = $acceso;
            }
        }

        return $salida;
    }

    /** El contexto vigente, o `null` si no hay ninguno elegido (o el elegido ya no es accesible). */
    public function actual(User $user): ?array
    {
        $guardado = session(self::SESION);
        if (! is_array($guardado) || empty($guardado['reg_medico'])) {
            return null;
        }

        $disponibles = $this->disponibles($user);
        $registro = (string) $guardado['reg_medico'];
        if (! isset($disponibles[$registro])) {
            return null;
        }

        $acceso = $disponibles[$registro];
        $specialty = $acceso['especialidades']->firstWhere('id', (int) ($guardado['specialty_id'] ?? 0))
            ?? $acceso['especialidades']->first();
        $office = ! empty($guardado['office_id'])
            ? $acceso['sedes']->firstWhere('id', (int) $guardado['office_id'])
            : null;

        $subespecialidades = $specialty === null
            ? collect()
            : $acceso['medico']->subespecialidadesDe($specialty);

        return [
            'reg_medico'     => $registro,
            'medico'         => $acceso['medico'],
            // ¿El contexto es un **centro**? El centro no atiende: si algún día tiene login, la web no le
            // ofrece «Atender» ni la consulta (la secretaría ya lo cubre por rol, esto es el seguro).
            'es_centro'      => $acceso['es_centro'],
            'especialidades' => $acceso['especialidades'],
            'specialty'      => $specialty,
            // Los **postgrados del médico de esa especialidad** (2026-10-19): son los que encienden módulos y
            // secciones de la consulta. Se suman todos (decisión del usuario): si hizo dos, ve los dos.
            'subespecialidades' => $subespecialidades,
            // Las **secciones de la consulta** que suman esos postgrados, ya resueltas del manifiesto: es lo que
            // consume el catálogo (`SeccionesDeLaConsulta`).
            'secciones_postgrados' => $this->seccionesDeLosPostgrados($specialty, $subespecialidades),
            'sedes'          => $acceso['sedes'],
            'office'         => $office,
        ];
    }

    /**
     * Los **`reg_medico` del contexto**: el del doctor (el suyo y los centros donde trabaja) o, en la
     * secretaría, sólo el del centro elegido.
     *
     * Es la lista con la que se leen los pacientes (la lista global del doctor) y con la que se valida el
     * "ver otra historia". Nunca incluye un consultorio ajeno: sale del médico del contexto.
     *
     * @param  array<string,mixed>  $contexto  el `contexto_clinico` de la petición
     * @return array<int,string>
     */
    public function registrosDelContexto(array $contexto): array
    {
        $medico = $contexto['medico'] ?? null;
        $registros = $medico instanceof Medico ? $medico->registrosDeTrabajo() : [];

        if ($registros === [] && ! empty($contexto['reg_medico'])) {
            $registros = [(string) $contexto['reg_medico']];
        }

        // El del contexto siempre está: es el tenant con el que se está trabajando.
        if (! empty($contexto['reg_medico']) && ! in_array((string) $contexto['reg_medico'], $registros, true)) {
            array_unshift($registros, (string) $contexto['reg_medico']);
        }

        return $registros;
    }

    /**
     * Un acceso al contexto, o `null` si el registro no tiene ficha de médico que mostrar.
     *
     * @param  Collection<int,Medico>  $medicosDelUsuario  las fichas del usuario (`medicos.user_id`)
     * @return array{reg_medico: string, medico: Medico, es_centro: bool, especialidades: Collection, sedes: Collection}|null
     */
    private function acceso(string $registro, Collection $medicosDelUsuario): ?array
    {
        // Los consultorios vigentes **del tenant**: activos, de su registro y de una sede activa. Sin filtrar
        // por `medico_id` (Fase 2): el consultorio es del consultorio, y el doctor que trabaja en un centro
        // tiene que ver todos sus consultorios, no sólo los que creó él.
        $sedes = Office::vigentesDe($registro)->orderBy('id')->get();

        // El dueño del registro: el doctor en su consultorio, o el centro donde está el consultorio. Se usa
        // para agrupar el menú de consultorios de la pantalla (el personal vs. el del centro).
        $centro = Medico::where('reg_medico', $registro)->orderBy('id')->first();

        // 1. La ficha del usuario **en este registro**: es el doctor en su consultorio personal, o el
        //    centro en el caso raro de que un centro tenga cuenta. El médico del contexto es esa ficha.
        $propio = $medicosDelUsuario->firstWhere('reg_medico', $registro);

        // 2. Si el usuario **tiene ficha de médico**, en los demás registros —los centros donde trabaja— el
        //    médico del contexto es **él**, no el centro: sus especialidades son las que deciden los módulos
        //    y su firma es la de la consulta.
        $duenio = $propio ?? $medicosDelUsuario->first();

        if ($duenio !== null) {
            return [
                'reg_medico'     => $registro,
                'medico'         => $duenio,
                'centro'         => $centro,
                'es_centro'      => (bool) $duenio->es_centro,
                'especialidades' => $duenio->specialties->where('activo', true)->values(),
                'sedes'          => $sedes,
            ];
        }

        // 3. Sin ficha de médico (la secretaría): el médico del contexto es la fila del **centro** y las
        //    especialidades que ofrece son la unión de las de sus doctores.
        if ($centro === null) {
            return null; // datos sin ficha de médico: no hay nombre ni especialidad que mostrar
        }

        return [
            'reg_medico'     => $registro,
            'medico'         => $centro,
            'centro'         => $centro,
            'es_centro'      => (bool) $centro->es_centro,
            'especialidades' => $this->especialidadesDelRegistro($registro, $centro),
            'sedes'          => $sedes,
        ];
    }

    /**
     * Las especialidades que **ofrece un registro** cuando quien lo mira no es su dueño: la unión de las de
     * los doctores que trabajan ahí (`medico_registros` con ese `reg_medico`) más la del propio registro,
     * activas y sin repetir.
     *
     * Es la regla de la secretaría: el **centro no tiene especialidades propias**, ofrece las de sus
     * doctores —lo que hace que el menú de la secretaría muestre los módulos de todas—. En un registro
     * heredado sin centro (una secretaría asignada al `reg_medico` de un doctor) la unión es la de ese
     * doctor, porque es el único vinculado.
     *
     * @return Collection<int, Specialty>
     */
    private function especialidadesDelRegistro(string $registro, Medico $duenio): Collection
    {
        $ids = MedicoRegistro::where('reg_medico', $registro)
            ->pluck('medico_id')
            ->push($duenio->id)
            ->filter()
            ->unique()
            ->values()
            ->all();

        return Medico::whereIn('id', $ids)
            ->with('specialties')
            ->get()
            ->flatMap(fn (Medico $medico) => $medico->specialties)
            ->where('activo', true)
            ->unique('id')
            ->sortBy(fn (Specialty $especialidad) => mb_strtolower($especialidad->name))
            ->values();
    }

    /**
     * Las claves de las secciones de la consulta que encienden los postgrados del médico, en el orden declarado
     * (el catálogo las inserta en el lugar del legado).
     *
     * Las secciones viven en el manifiesto de la especialidad (`subespecialidades.<slug>.secciones`), no en la
     * base: el pivote sólo dice **qué postgrados tiene** el médico. Se ignoran los postgrados que el manifiesto
     * no declara (una fila vieja de la base no enciende nada que la especialidad no haya escrito).
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\Subspecialty>  $subespecialidades
     * @return array<int,string>
     */
    private function seccionesDeLosPostgrados(?Specialty $specialty, $subespecialidades): array
    {
        if ($specialty === null || $subespecialidades->isEmpty()) {
            return [];
        }

        $declaradas = (array) ConfiguracionDeLaEspecialidad::valor($specialty, 'subespecialidades', []);
        $salida = [];

        foreach ($subespecialidades as $subespecialidad) {
            foreach ((array) ($declaradas[$subespecialidad->slug]['secciones'] ?? []) as $seccion) {
                $salida[] = (string) $seccion;
            }
        }

        return array_values(array_unique($salida));
    }

    /**
     * **Los módulos del menú del contexto**: los de la especialidad **más** los de sus postgrados.
     *
     * El postgrado no reemplaza la especialidad: nefrología pediátrica agrega lo suyo a lo que ya da
     * pediatría, y los módulos repetidos se cuentan una vez (si la especialidad ya lo mostraba, manda su
     * orden). Misma regla de visibilidad que `Specialty::modulosVisibles()`.
     *
     * @param  array<string,mixed>  $contexto
     * @param  array<int,string>  $roles
     * @return \Illuminate\Support\Collection<int, \App\Models\ModuloClinico>
     */
    public function modulos(array $contexto, array $roles)
    {
        $modulos = $contexto['specialty']?->modulosVisibles($roles) ?? collect();

        foreach ($contexto['subespecialidades'] ?? [] as $subespecialidad) {
            $modulos = $modulos->merge($subespecialidad->modulosVisibles($roles));
        }

        return $modulos
            ->unique('slug')
            ->sortBy(fn ($modulo) => sprintf('%05d-%05d-%s', $modulo->pivot->orden, $modulo->orden, $modulo->nombre))
            ->values();
    }

    /**
     * Fija el contexto. Valida contra los accesos reales: no se puede elegir un `reg_medico` ajeno ni
     * una sede de otro médico.
     */
    public function fijar(User $user, string $regMedico, ?int $specialtyId = null, ?int $officeId = null): array
    {
        $disponibles = $this->disponibles($user);
        if (! isset($disponibles[$regMedico])) {
            throw ValidationException::withMessages(['reg_medico' => 'No tenés acceso a los datos de ese médico.']);
        }

        $acceso = $disponibles[$regMedico];
        $specialty = $acceso['especialidades']->firstWhere('id', $specialtyId) ?? $acceso['especialidades']->first();
        $office = $officeId ? $acceso['sedes']->firstWhere('id', $officeId) : null;

        session([self::SESION => [
            'reg_medico'   => $regMedico,
            'specialty_id' => $specialty?->id,
            'office_id'    => $office?->id,
        ]]);

        return $this->actual($user);
    }

    /** Cambiar de especialidad o de sede sin volver a elegir el médico. */
    public function cambiar(User $user, ?int $specialtyId = null, ?int $officeId = null): ?array
    {
        $actual = $this->actual($user);
        if (! $actual) {
            return null;
        }

        return $this->fijar($user, $actual['reg_medico'], $specialtyId, $officeId);
    }

    public function limpiar(): void
    {
        session()->forget(self::SESION);
    }

    /** @return Collection<int, Medico> */
    private function medicosDelUsuario(User $user): Collection
    {
        // El médico del usuario puede estar enlazado por `medicos.user_id` o, como pasa con las cuentas
        // creadas desde el panel, por las filas de `medico_registros` de ese usuario (`medico_id`). Las dos
        // vías son la misma persona; se unen para que el médico del contexto sea **siempre él** y no el
        // dueño de cada `reg_medico`.
        $porUsuario = Medico::where('user_id', $user->id)->get();

        $porRegistro = Medico::whereIn(
            'id',
            MedicoRegistro::where('user_id', $user->id)
                ->whereNotNull('medico_id')
                ->pluck('medico_id')
                ->all()
        )->get();

        return $porUsuario->merge($porRegistro)->unique('id')->values();
    }
}
