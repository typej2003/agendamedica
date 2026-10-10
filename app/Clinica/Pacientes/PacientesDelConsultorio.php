<?php

namespace App\Clinica\Pacientes;

use App\Models\Paciente;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lectura de los pacientes del `reg_medico` del contexto (`medico_pacientes` + `pacientes` + `consultas`).
 *
 * Lee de las tablas reales, no de `refresh-data`, que tiene el doble shape que ya documentó el móvil.
 * **Todo** se filtra por el `reg_medico`: un paciente de otro médico no se devuelve ni se confirma que
 * exista. No sabe de HTTP ni de HTML; lo que muestra la pantalla lo arman los ViewModels de `Vistas/`.
 *
 * El número de historia vive en `historias` y en `medico_pacientes`, y los dos los escriben juntos todos
 * los caminos de sync (carga inicial, escritorio, alta del app, `AsignarHistoria`). Acá se lee **del
 * pivote**, que es el vínculo por médico —el mismo que usan la agenda y los cumpleaños—, y se limpia con
 * `CamposDePaciente`: el legado lo guarda como texto y acepta vacíos y espacios.
 */
class PacientesDelConsultorio
{
    private const COLUMNAS = [
        'pacientes.id',
        'pacientes.nombres',
        'pacientes.apellidos',
        'pacientes.cedula',
        'pacientes.fnacimiento',
        'pacientes.sexo',
        'pacientes.telefono',
        'pacientes.email',
        // Los datos de filiación y contacto que la ficha muestra (WEB-3.2: la ficha tiene que decir con
        // quién se atiende al paciente, no solo su teléfono) y el código del seguro, que la pantalla
        // resuelve contra el catálogo del consultorio.
        'pacientes.direccion',
        'pacientes.profesion',
        'pacientes.ocupacion',
        'pacientes.codesegemp',
        'medico_pacientes.numhistoria',
    ];

    public const POR_PAGINA = 25;

    /** El listado con la búsqueda por nombre, apellido, cédula o n.º de historia, paginado. */
    public function listar(string $regMedico, string $buscar = ''): LengthAwarePaginator
    {
        return $this->delConsultorio($regMedico)
            ->when($buscar !== '', fn ($consulta) => BusquedaDePacientes::aplicar($consulta, $buscar))
            ->orderBy('pacientes.apellidos')
            ->orderBy('pacientes.nombres')
            ->select(self::COLUMNAS)
            ->distinct()
            ->paginate(self::POR_PAGINA)
            ->withQueryString();
    }

    /**
     * La ficha de un paciente del consultorio, o `null` si no existe o es de otro médico.
     *
     * El `numhistoria` que devuelve ya viene limpio —texto sin espacios, `null` si el vínculo todavía no
     * tiene número—, para que ninguna pantalla repita el `!== null` que el `''` del legado rompe.
     */
    public function ficha(string $regMedico, int $pacienteId): ?object
    {
        $ficha = $this->delConsultorio($regMedico)
            ->where('pacientes.id', $pacienteId)
            ->select(self::COLUMNAS)
            ->first();

        if ($ficha !== null) {
            $ficha->numhistoria = CamposDePaciente::numeroDeHistoria($ficha->numhistoria);
        }

        return $ficha;
    }

    /**
     * El **paciente completo** para editarlo (la ficha de la pantalla lee un subconjunto de columnas),
     * más su número de historia en este consultorio. `null` si no es paciente del `reg_medico`: un
     * paciente de otro médico no se edita ni se confirma que exista (404 en el controlador).
     *
     * Se consulta primero el vínculo —que es lo que dice de quién es el paciente— y recién después el
     * modelo: así la pantalla de edición nunca toca una ficha ajena, ni siquiera para leerla.
     *
     * @return array{paciente:Paciente, numhistoria:?string}|null
     */
    public function editable(string $regMedico, int $pacienteId): ?array
    {
        $vinculo = DB::table('medico_pacientes')
            ->where('reg_medico', $regMedico)
            ->where('paciente_id', $pacienteId)
            ->first(['numhistoria']);

        if ($vinculo === null) {
            return null;
        }

        $paciente = Paciente::find($pacienteId);

        if ($paciente === null) {
            return null;
        }

        return [
            'paciente'    => $paciente,
            'numhistoria' => CamposDePaciente::numeroDeHistoria($vinculo->numhistoria),
        ];
    }

    /**
     * Las últimas consultas de la historia del paciente; vacío si todavía no tiene n.º de historia.
     *
     * El número llega como texto (así está en el pivote) y acá se vuelve entero, que es como está en
     * `consultas`. Si no es numérico no se busca nada: `(int)` lo volvería `0` y traería las consultas de
     * otro paciente.
     */
    public function consultasDe(string $regMedico, ?string $numhistoria, int $limite = 20): Collection
    {
        $numero = CamposDePaciente::historiaNumerica($numhistoria);

        if ($numero === null) {
            return collect();
        }

        return DB::table('consultas')
            ->where('reg_medico', $regMedico)
            ->where('numhistoria', $numero)
            ->orderByDesc('fecha')
            ->limit($limite)
            ->get(['id', 'fecha', 'nroconsulta', 'enfermedadactual']);
    }

    /**
     * Lo que se escribe en el buscador de "Nueva cita": pocos pacientes para elegir uno. Se exigen dos
     * caracteres porque con uno solo la lista sería el consultorio entero.
     *
     * @return array<int, array{id:int, nombre:string, cedula:?string, numhistoria:?int}>
     */
    public function sugerencias(string $regMedico, string $buscar, int $limite = 10): array
    {
        if (mb_strlen($buscar) < 2) {
            return [];
        }

        $filas = BusquedaDePacientes::aplicar($this->delConsultorio($regMedico), $buscar)
            ->orderBy('pacientes.apellidos')
            ->orderBy('pacientes.nombres')
            ->limit($limite)
            ->get([
                'pacientes.id',
                'pacientes.nombres',
                'pacientes.apellidos',
                'pacientes.cedula',
                'medico_pacientes.numhistoria',
            ]);

        return $filas->map(fn ($fila) => [
            'id'          => (int) $fila->id,
            'nombre'      => CamposDePaciente::nombre($fila->apellidos, $fila->nombres),
            'cedula'      => CamposDePaciente::cedula($fila->cedula),
            'numhistoria' => CamposDePaciente::historiaNumerica($fila->numhistoria),
        ])->values()->all();
    }

    private function delConsultorio(string $regMedico)
    {
        return DB::table('medico_pacientes')
            ->join('pacientes', 'pacientes.id', '=', 'medico_pacientes.paciente_id')
            ->where('medico_pacientes.reg_medico', $regMedico);
    }
}
