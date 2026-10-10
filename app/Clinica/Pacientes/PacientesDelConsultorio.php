<?php

namespace App\Clinica\Pacientes;

use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lectura de los pacientes del `reg_medico` del contexto (`medico_pacientes` + `pacientes` + `consultas`).
 *
 * Lee de las tablas reales, no de `refresh-data`, que tiene el doble shape que ya documentó el móvil.
 * **Todo** se filtra por el `reg_medico`: un paciente de otro médico no se devuelve ni se confirma que
 * exista. No sabe de HTTP ni de HTML; lo que muestra la pantalla lo arman los ViewModels de `Vistas/`.
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
        'medico_pacientes.numhistoria',
    ];

    public const POR_PAGINA = 25;

    /** El listado con la búsqueda por nombre, apellido, cédula o n.º de historia, paginado. */
    public function listar(string $regMedico, string $buscar = ''): LengthAwarePaginator
    {
        return $this->delConsultorio($regMedico)
            ->when($buscar !== '', fn ($consulta) => $consulta->where($this->filtroDeBusqueda($buscar)))
            ->orderBy('pacientes.apellidos')
            ->orderBy('pacientes.nombres')
            ->select(self::COLUMNAS)
            ->distinct()
            ->paginate(self::POR_PAGINA)
            ->withQueryString();
    }

    /** La ficha de un paciente del consultorio, o `null` si no existe o es de otro médico. */
    public function ficha(string $regMedico, int $pacienteId): ?object
    {
        return $this->delConsultorio($regMedico)
            ->where('pacientes.id', $pacienteId)
            ->select(self::COLUMNAS)
            ->first();
    }

    /** Las últimas consultas de la historia del paciente; vacío si todavía no tiene n.º de historia. */
    public function consultasDe(string $regMedico, ?int $numhistoria, int $limite = 20): Collection
    {
        if ($numhistoria === null) {
            return collect();
        }

        return DB::table('consultas')
            ->where('reg_medico', $regMedico)
            ->where('numhistoria', $numhistoria)
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

        $filas = $this->delConsultorio($regMedico)
            ->where($this->filtroDeBusqueda($buscar))
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
            'nombre'      => trim(($fila->apellidos ?? '') . ', ' . ($fila->nombres ?? ''), ', '),
            'cedula'      => $fila->cedula,
            'numhistoria' => $fila->numhistoria !== null ? (int) $fila->numhistoria : null,
        ])->values()->all();
    }

    private function delConsultorio(string $regMedico)
    {
        return DB::table('medico_pacientes')
            ->join('pacientes', 'pacientes.id', '=', 'medico_pacientes.paciente_id')
            ->where('medico_pacientes.reg_medico', $regMedico);
    }

    /** El grupo `where` de la búsqueda: agrupado para que el `or` no se escape del filtro por `reg_medico`. */
    private function filtroDeBusqueda(string $buscar): Closure
    {
        return function ($filtro) use ($buscar) {
            $filtro->where('pacientes.nombres', 'like', "%{$buscar}%")
                ->orWhere('pacientes.apellidos', 'like', "%{$buscar}%")
                ->orWhere('pacientes.cedula', 'like', "%{$buscar}%")
                ->orWhere('medico_pacientes.numhistoria', 'like', "%{$buscar}%");
        };
    }
}
