<?php

namespace App\Http\Controllers\Clinica;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Pacientes del `reg_medico` del contexto: listado con búsqueda y ficha. **Sólo lectura** por ahora
 * (primer módulo implementado de la web clínica, PLAN-WEB.md F1).
 *
 * Lee de las tablas reales (`medico_pacientes` + `pacientes` + `historias`), no de `refresh-data`, que
 * tiene el doble shape que ya documentó el móvil. Todo filtrado por el `reg_medico` del contexto.
 */
class PacienteController extends Controller
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
        'medico_pacientes.numhistoria',
    ];

    public function index(Request $request)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $buscar = trim((string) $request->query('buscar', ''));

        $pacientes = DB::table('medico_pacientes')
            ->join('pacientes', 'pacientes.id', '=', 'medico_pacientes.paciente_id')
            ->where('medico_pacientes.reg_medico', $contexto['reg_medico'])
            ->when($buscar !== '', function ($consulta) use ($buscar) {
                $consulta->where(function ($filtro) use ($buscar) {
                    $filtro->where('pacientes.nombres', 'like', "%{$buscar}%")
                        ->orWhere('pacientes.apellidos', 'like', "%{$buscar}%")
                        ->orWhere('pacientes.cedula', 'like', "%{$buscar}%")
                        ->orWhere('medico_pacientes.numhistoria', 'like', "%{$buscar}%");
                });
            })
            ->orderBy('pacientes.apellidos')
            ->orderBy('pacientes.nombres')
            ->select(self::COLUMNAS)
            ->distinct()
            ->paginate(25)
            ->withQueryString();

        return view('clinica.pacientes.index', [
            'pacientes' => $pacientes,
            'buscar'    => $buscar,
        ]);
    }

    /**
     * Los pacientes del contexto que coinciden con lo que se está escribiendo en el buscador de
     * "Nueva cita" (apellido, nombre, cédula o n.º de historia). Devuelve JSON y **pocos**: es un
     * desplegable para elegir uno, no un listado.
     *
     * Se exigen dos caracteres: con uno solo la lista sería el consultorio entero.
     */
    public function buscar(Request $request)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $buscar = trim((string) $request->query('q', ''));

        if (mb_strlen($buscar) < 2) {
            return response()->json([]);
        }

        $pacientes = DB::table('medico_pacientes')
            ->join('pacientes', 'pacientes.id', '=', 'medico_pacientes.paciente_id')
            ->where('medico_pacientes.reg_medico', $contexto['reg_medico'])
            ->where(function ($filtro) use ($buscar) {
                $filtro->where('pacientes.nombres', 'like', "%{$buscar}%")
                    ->orWhere('pacientes.apellidos', 'like', "%{$buscar}%")
                    ->orWhere('pacientes.cedula', 'like', "%{$buscar}%")
                    ->orWhere('medico_pacientes.numhistoria', 'like', "%{$buscar}%");
            })
            ->orderBy('pacientes.apellidos')
            ->orderBy('pacientes.nombres')
            ->limit(10)
            ->get([
                'pacientes.id',
                'pacientes.nombres',
                'pacientes.apellidos',
                'pacientes.cedula',
                'medico_pacientes.numhistoria',
            ]);

        return response()->json($pacientes->map(fn ($fila) => [
            'id'          => (int) $fila->id,
            'nombre'      => trim(($fila->apellidos ?? '') . ', ' . ($fila->nombres ?? ''), ', '),
            'cedula'      => $fila->cedula,
            'numhistoria' => $fila->numhistoria !== null ? (int) $fila->numhistoria : null,
        ])->values());
    }

    public function ver(Request $request, int $paciente)
    {
        $contexto = $request->attributes->get('contexto_clinico');

        $ficha = DB::table('medico_pacientes')
            ->join('pacientes', 'pacientes.id', '=', 'medico_pacientes.paciente_id')
            ->where('medico_pacientes.reg_medico', $contexto['reg_medico'])
            ->where('pacientes.id', $paciente)
            ->select(self::COLUMNAS)
            ->first();

        // Un paciente de otro médico no se muestra ni se confirma que exista.
        abort_unless($ficha, 404);

        $consultas = collect();
        if ($ficha->numhistoria !== null) {
            $consultas = DB::table('consultas')
                ->where('reg_medico', $contexto['reg_medico'])
                ->where('numhistoria', $ficha->numhistoria)
                ->orderByDesc('fecha')
                ->limit(20)
                ->get(['id', 'fecha', 'nroconsulta', 'enfermedadactual']);
        }

        return view('clinica.pacientes.ver', [
            'paciente'  => $ficha,
            'consultas' => $consultas,
        ]);
    }
}
