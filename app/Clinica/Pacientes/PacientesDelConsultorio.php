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
 * `CamposDePaciente`: el legado lo guarda como texto y acepta vacíos y espacios. `FiltroDePacientes` es
 * quien dice, en SQL y en un solo lugar, qué filas de ese pivote están "sin historia" (WEB-3.3).
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

    /**
     * El listado con la búsqueda por nombre, apellido, cédula o n.º de historia, paginado y —desde
     * WEB-3.3— filtrable por "tiene historia / no tiene historia".
     *
     * El filtro es lo que la secretaría necesita para ir a llenar las historias: `medico_pacientes` es
     * donde vive el número y `FiltroDePacientes` es el único lugar donde está escrito qué significa que
     * no lo tenga (el `null` **y** el `''` del legado), para que la pantalla no diga "Sin historia" en
     * una fila que su propio filtro no trae.
     */
    public function listar(string $regMedico, string $buscar = '', string $filtro = FiltroDePacientes::TODOS): LengthAwarePaginator
    {
        return $this->conFiltro($this->delConsultorio($regMedico), $filtro)
            ->when($buscar !== '', fn ($consulta) => BusquedaDePacientes::aplicar($consulta, $buscar))
            ->orderBy('pacientes.apellidos')
            ->orderBy('pacientes.nombres')
            ->select(self::COLUMNAS)
            ->distinct()
            ->paginate(self::POR_PAGINA)
            ->withQueryString();
    }

    /**
     * Cuántos pacientes hay de cada lado —todos, sin historia y con historia—, **con la misma búsqueda
     * aplicada**: el segmentado los usa de etiqueta, y un contador que ignore lo que el usuario escribió
     * en el buscador prometería filas que la tabla no muestra.
     *
     * @return array{todos:int, sinHistoria:int, conHistoria:int}
     */
    public function conteos(string $regMedico, string $buscar = ''): array
    {
        $base = fn () => $this->delConsultorio($regMedico)
            ->when($buscar !== '', fn ($consulta) => BusquedaDePacientes::aplicar($consulta, $buscar));

        return [
            'todos'       => $base()->distinct()->count('pacientes.id'),
            'sinHistoria' => $base()->whereRaw(FiltroDePacientes::sqlSinHistoria())->distinct()->count('pacientes.id'),
            'conHistoria' => $base()->whereRaw(FiltroDePacientes::sqlConHistoria())->distinct()->count('pacientes.id'),
        ];
    }

    /**
     * El **vínculo** del paciente con el consultorio, que es lo que dice de quién es: el `numhistoria`
     * del pivote (que es el que muestra la ficha y el que decide si hay algo que asignar) y el
     * `reg_medico` con el que quedó guardado.
     *
     * Devuelve `null` cuando el paciente no es del `reg_medico` del contexto: la pantalla que asigna la
     * historia responde **404** con esto, igual que la ficha, sin confirmar que exista en otro médico.
     *
     * Es un `object` y no un `MedicoPaciente` a propósito: el pivote no tiene clave primaria propia y la
     * Action decide con el vínculo, no con el modelo.
     */
    public function vinculo(string $regMedico, int $pacienteId): ?object
    {
        $vinculo = DB::table('medico_pacientes')
            ->where('reg_medico', $regMedico)
            ->where('paciente_id', $pacienteId)
            ->first(['paciente_id', 'numhistoria', 'reg_medico']);

        if ($vinculo !== null) {
            $vinculo->numhistoria = CamposDePaciente::numeroDeHistoria($vinculo->numhistoria);
        }

        return $vinculo;
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

    /**
     * El segmentado del listado (WEB-3.3): `sin-historia` deja sólo los que todavía no tienen número y
     * `con-historia` los que sí. La condición sale de `FiltroDePacientes` —no se escribe acá— porque es
     * la misma que usan los contadores de las etiquetas.
     */
    private function conFiltro($consulta, string $filtro)
    {
        return match ($filtro) {
            FiltroDePacientes::SIN_HISTORIA => $consulta->whereRaw(FiltroDePacientes::sqlSinHistoria()),
            FiltroDePacientes::CON_HISTORIA => $consulta->whereRaw(FiltroDePacientes::sqlConHistoria()),
            default                         => $consulta,
        };
    }
}
