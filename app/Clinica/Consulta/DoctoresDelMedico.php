<?php

namespace App\Clinica\Consulta;

use App\Clinica\Pacientes\BusquedaDePacientes;
use App\Clinica\Pacientes\CamposDePaciente;
use App\Models\Doctor;
use App\Models\Especialidad;
use Illuminate\Support\Collection;

/**
 * **El catálogo de médicos a los que se refiere** (`doctores`, WEB-5.3a): el buscador de la pestaña *Referencias*, la
 * resolución del médico referido (su nombre, especialidad, clínica, teléfono y dirección) y las especialidades que
 * ofrece el alta rápida.
 *
 * ## Qué es `doctores` (verificado en la fuente)
 *
 * No son los usuarios del sistema (`medicos`): es la **libreta de colegas** de cada consultorio, a quienes el médico
 * deriva a sus pacientes. `d_listado_medicos.srd` (el `dw_24` de la pestaña *Referencias*) la lista ordenada por
 * `codeespecial` y `nombres`, con el nombre armado `nombres + ' ' + apellidos`, y se filtra escribiendo
 * (`sle_8.ue_tecla`, `compute_1 like '%…%'`). Su clave es `cedula` —un `numeric(15,0)` que en la práctica **no es una
 * cédula**: el alta del escritorio le pone el siguiente correlativo (`w_nuevo_medico.srw`, `ue_nuevo`: el mayor + 1
 * rellenado a 8 dígitos)— y `codeespecial` apunta a `especial.codeespecial`.
 *
 * ## Cómo se resuelve un médico referido
 *
 * Con la vara del móvil (`ReportesRepository._Catalogo`) y de la historia del paciente: **primero dentro de los
 * registros del médico y, si no está ahí, por código en cualquiera** —los datos del legado traen `reg_medico`
 * inconsistentes en los catálogos—. Así una referencia que viene del escritorio se ve con su médico aunque la fila del
 * catálogo haya quedado bajo otro registro. El **buscador**, en cambio, sólo ofrece los del propio catálogo del
 * médico: es una herramienta para elegir, no para recorrer el de otro consultorio.
 *
 * La búsqueda no distingue tildes ni mayúsculas (`BusquedaDePacientes::normalizar`, que es el único mapa de acentos
 * del proyecto) y por eso no es un `LIKE` de SQL (trampa 13).
 *
 * Es de **sólo lectura**: el alta es `App\Actions\Consulta\AgregarDoctorAlCatalogo`.
 */
final class DoctoresDelMedico
{
    /** El tope de resultados del buscador: es para elegir, no para listar el catálogo. */
    public const MAX_RESULTADOS = 20;

    /**
     * Los médicos del catálogo que coinciden con lo escrito (nombre, apellido, clínica o especialidad), por
     * especialidad y nombre.
     *
     * @param  array<int,string>  $registros  los `reg_medico` del médico del contexto
     * @return Collection<int,object{cedula:int, nombre:string, especialidad:?string, clinica:?string, telefono:?string, direccion:?string, ciudad:?string, codeespecial:?string}>
     */
    public function buscar(array $registros, string $texto): Collection
    {
        $clave = BusquedaDePacientes::normalizar(trim($texto));
        $especialidades = $this->nombresDeEspecialidad($registros);

        return Doctor::whereIn('reg_medico', $registros)
            ->orderBy('codeespecial')
            ->orderBy('nombres')
            ->orderBy('apellidos')
            ->get()
            ->unique(fn (Doctor $doctor) => (int) $doctor->cedula)
            ->map(fn (Doctor $doctor) => $this->resolver($doctor, $especialidades))
            ->filter(function (object $doctor) use ($clave) {
                if ($clave === '') {
                    return true;
                }

                return str_contains(
                    BusquedaDePacientes::normalizar($doctor->nombre . ' ' . $doctor->clinica . ' ' . $doctor->especialidad),
                    $clave,
                );
            })
            ->values()
            ->take(self::MAX_RESULTADOS);
    }

    /**
     * **Toda la libreta de colegas del médico** (`doctores`), para un desplegable que no busca: su cédula y su
     * nombre, por apellido y nombre.
     *
     * Es la misma lectura que `buscar` —el buscador de la pestaña *Referencias*—, con la misma resolución del
     * nombre y la especialidad, pero **sin su tope de resultados**: `MAX_RESULTADOS` es *para elegir*, no para
     * listar la libreta, y el `referido` del eco mamario (WEB-6.4) elige de la libreta completa.
     *
     * @param  array<int,string>  $registros  los `reg_medico` del médico del contexto
     * @return Collection<int,object{cedula:int, nombre:string, especialidad:?string, clinica:?string, telefono:?string, direccion:?string, ciudad:?string, codeespecial:?string}>
     */
    public function delCatalogo(array $registros): Collection
    {
        $especialidades = $this->nombresDeEspecialidad($registros);

        return Doctor::whereIn('reg_medico', $registros)
            ->orderBy('apellidos')
            ->orderBy('nombres')
            ->orderBy('id')
            ->get()
            ->unique(fn (Doctor $doctor) => (int) $doctor->cedula)
            ->map(fn (Doctor $doctor) => $this->resolver($doctor, $especialidades))
            ->values();
    }

    /**
     * El médico por su código (la `cedula` del catálogo), o `null` si no está. Primero en los registros del médico y,
     * si no, en cualquiera.
     *
     * @param  array<int,string>  $registros
     */
    public function porCedula(array $registros, int $cedula): ?object
    {
        return $this->porCedulas($registros, [$cedula])[$cedula] ?? null;
    }

    /**
     * Varios médicos de una sola lectura (la lista de referencias de una consulta).
     *
     * @param  array<int,string>  $registros
     * @param  array<int,int>  $cedulas
     * @return array<int,object>  `cedula => médico` (sólo los que existen)
     */
    public function porCedulas(array $registros, array $cedulas): array
    {
        $cedulas = array_values(array_unique(array_map('intval', $cedulas)));

        if ($cedulas === []) {
            return [];
        }

        $propios = Doctor::whereIn('reg_medico', $registros)->whereIn('cedula', $cedulas)->orderBy('id')->get();
        $faltan = array_diff($cedulas, $propios->pluck('cedula')->map(fn ($cedula) => (int) $cedula)->all());
        $ajenos = $faltan === [] ? collect() : Doctor::whereIn('cedula', $faltan)->orderBy('id')->get();

        $especialidades = $this->nombresDeEspecialidad($registros);
        $resueltos = [];

        foreach ($propios->concat($ajenos) as $doctor) {
            $resueltos[(int) $doctor->cedula] ??= $this->resolver($doctor, $especialidades);
        }

        return $resueltos;
    }

    /**
     * ¿El médico está en el catálogo **propio** (los registros del médico)? Es lo que exige agregar una referencia
     * desde la web: sólo se refiere a quien el médico tiene en su libreta.
     *
     * @param  array<int,string>  $registros
     */
    public function estaEnElCatalogo(array $registros, int $cedula): bool
    {
        return Doctor::whereIn('reg_medico', $registros)->where('cedula', $cedula)->exists();
    }

    /**
     * Las especialidades del catálogo del médico (`especial`) para el `<select>` del alta: `código => nombre`, por
     * nombre. Una por código aunque el catálogo lo repita en varios registros.
     *
     * @param  array<int,string>  $registros
     * @return array<string,string>
     */
    public function especialidades(array $registros): array
    {
        $lista = $this->nombresDeEspecialidad($registros);
        asort($lista, SORT_NATURAL | SORT_FLAG_CASE);

        return $lista;
    }

    // ------------------------------------------------------------------------------------------ Adentro

    /**
     * `código => nombre` de las especialidades de los registros del médico; lo que falte se resuelve contra el
     * catálogo de cualquier registro cuando se necesita (`resolver`).
     *
     * @param  array<int,string>  $registros
     * @return array<string,string>
     */
    private function nombresDeEspecialidad(array $registros): array
    {
        $nombres = [];

        foreach (Especialidad::whereIn('reg_medico', $registros)->orderBy('id')->get(['codeespecial', 'especialidad']) as $fila) {
            $codigo = CamposDePaciente::texto($fila->codeespecial);
            $nombre = CamposDePaciente::texto($fila->especialidad);

            if ($codigo !== null && $nombre !== null) {
                $nombres[$codigo] ??= $nombre;
            }
        }

        return $nombres;
    }

    /**
     * @param  array<string,string>  $especialidades  las del médico, `código => nombre`
     */
    private function resolver(Doctor $doctor, array $especialidades): object
    {
        $codigo = CamposDePaciente::texto($doctor->codeespecial);
        $nombre = trim(implode(' ', array_filter([
            CamposDePaciente::texto($doctor->nombres),
            CamposDePaciente::texto($doctor->apellidos),
        ])));

        $especialidad = $codigo === null
            ? null
            : ($especialidades[$codigo] ?? CamposDePaciente::texto(Especialidad::where('codeespecial', $codigo)->value('especialidad')));

        return (object) [
            'cedula' => (int) $doctor->cedula,
            'nombre' => $nombre !== '' ? $nombre : 'Médico sin nombre',
            'codeespecial' => $codigo,
            'especialidad' => $especialidad,
            'clinica' => CamposDePaciente::texto($doctor->clinica),
            'direccion' => CamposDePaciente::texto($doctor->direccion),
            'telefono' => CamposDePaciente::texto($doctor->telefono),
            'ciudad' => CamposDePaciente::texto($doctor->ciudad),
        ];
    }
}
