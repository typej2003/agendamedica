<?php

namespace App\Services;

use App\Models\Cola;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\Paciente;
use App\Models\Specialty;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class PacienteAtencionContextService
{
    /**
     * Cupos máximos de atención por día por médico por defecto
     */
    public const LIMITE_CUPOS_DIARIOS = 15;

    /**
     * Obtiene el contexto completo del paciente a partir de su número de teléfono.
     * Consulta Paciente, MedicoPaciente, Medico y Specialty.
     *
     * @param string $telefono
     * @return array
     */
    public function obtenerContextoPaciente(string $telefono): array
    {
        $ultimosDigitos = substr(preg_replace('/\D/', '', $telefono), -8);

        $paciente = null;
        if (!empty($ultimosDigitos)) {
            try {
                $paciente = Paciente::where('telefono', 'like', "%{$ultimosDigitos}%")->first();
            } catch (\Throwable $e) {
                Log::warning('PacienteAtencionContextService: error al buscar paciente: ' . $e->getMessage());
            }
        }

        // Catálogo de especialidades reales (modelo Specialty)
        $especialidades = $this->obtenerEspecialidadesDisponibles();

        // 1. Paciente NO registrado
        if (!$paciente) {
            return [
                'existe' => false,
                'nombre' => null,
                'numhistoria' => null,
                'medicos_asociados' => [],
                'tiene_medicos' => false,
                'especialidades' => $especialidades,
            ];
        }

        // 2. Paciente SÍ registrado
        $nombreCompleto = trim("{$paciente->nombres} {$paciente->apellidos}");
        $numhistoria = $paciente->numhistoria;

        // Consultar el modelo relacional MedicoPaciente
        $medicosAsociados = [];
        try {
            $relaciones = MedicoPaciente::where('numhistoria', $numhistoria)->get();
            $regs = $relaciones->pluck('reg_medico')->filter()->unique()->toArray();
            $medicoIds = $relaciones->pluck('medico_id')->filter()->unique()->toArray();

            if (!empty($regs) || !empty($medicoIds)) {
                $medicosQuery = Medico::with('specialties');

                if (!empty($regs) && !empty($medicoIds)) {
                    $medicosQuery->where(function ($q) use ($regs, $medicoIds) {
                        $q->whereIn('reg_medico', $regs)
                          ->orWhereIn('id', $medicoIds);
                    });
                } elseif (!empty($regs)) {
                    $medicosQuery->whereIn('reg_medico', $regs);
                } else {
                    $medicosQuery->whereIn('id', $medicoIds);
                }

                $medicos = $medicosQuery->get();

                foreach ($medicos as $m) {
                    $esp = $m->specialties->pluck('name')->first() ?? $m->biography ?? 'Medicina General';
                    $reg = $m->reg_medico ?? (string)$m->id;

                    $medicosAsociados[] = [
                        'id' => $m->id,
                        'reg_medico' => $reg,
                        'nombre' => trim("Dr(a). {$m->name} {$m->lastname}"),
                        'especialidad' => $esp,
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('PacienteAtencionContextService: error al consultar MedicoPaciente: ' . $e->getMessage());
        }

        return [
            'existe' => true,
            'nombre' => $nombreCompleto,
            'numhistoria' => $numhistoria,
            'medicos_asociados' => $medicosAsociados,
            'tiene_medicos' => !empty($medicosAsociados),
            'especialidades' => $especialidades,
        ];
    }

    /**
     * Consulta el modelo Cola para encontrar el día más próximo donde le queda cupo al médico
     * buscando por su campo reg_medico.
     *
     * @param string $regMedico
     * @param int $diasMaximos
     * @return array
     */
    public function consultarProximoDiaDisponible(string $regMedico, int $diasMaximos = 30): array
    {
        try {
            $medico = Medico::with('specialties')
                ->where('reg_medico', $regMedico)
                ->orWhere('id', $regMedico)
                ->first();

            $nombreMedico = $medico ? trim("Dr(a). {$medico->name} {$medico->lastname}") : "Médico (Registro: {$regMedico})";
            $especialidad = $medico ? ($medico->specialties->pluck('name')->first() ?? 'Medicina General') : 'Consulta';
            $regFinal = $medico->reg_medico ?? $regMedico;

            $hoy = Carbon::today();

            for ($i = 0; $i < $diasMaximos; $i++) {
                $fechaEvaluar = $hoy->copy()->addDays($i);

                // Omitir domingos
                if ($fechaEvaluar->isSunday()) {
                    continue;
                }

                $citasOcupadas = Cola::where('reg_medico', $regFinal)
                    ->whereDate('fecha', $fechaEvaluar->toDateString())
                    ->count();

                if ($citasOcupadas < self::LIMITE_CUPOS_DIARIOS) {
                    $cuposRestantes = self::LIMITE_CUPOS_DIARIOS - $citasOcupadas;
                    $diaTexto = $fechaEvaluar->locale('es')->isoFormat('dddd D [de] MMMM [de] YYYY');

                    return [
                        'disponible' => true,
                        'reg_medico' => $regFinal,
                        'medico' => $nombreMedico,
                        'especialidad' => $especialidad,
                        'fecha' => $fechaEvaluar->toDateString(),
                        'fecha_formateada' => ucfirst($diaTexto),
                        'cupos_disponibles' => $cuposRestantes,
                    ];
                }
            }

            return [
                'disponible' => false,
                'reg_medico' => $regFinal,
                'medico' => $nombreMedico,
                'mensaje' => "El {$nombreMedico} no cuenta con cupos disponibles en los próximos {$diasMaximos} días.",
            ];

        } catch (\Throwable $e) {
            Log::error('PacienteAtencionContextService::consultarProximoDiaDisponible error: ' . $e->getMessage());
            return [
                'disponible' => false,
                'error' => 'No se pudo consultar la agenda en este momento.',
            ];
        }
    }

    /**
     * Busca los médicos asociados a una especialidad dada.
     */
    public function buscarMedicosPorEspecialidad(string $nombreEspecialidad): array
    {
        try {
            $specialty = Specialty::where('name', 'like', "%{$nombreEspecialidad}%")->first();

            if ($specialty) {
                $medicos = $specialty->medicos()->get();
            } else {
                $medicos = Medico::where('biography', 'like', "%{$nombreEspecialidad}%")->get();
            }

            $resultado = [];
            foreach ($medicos as $m) {
                $resultado[] = [
                    'reg_medico' => $m->reg_medico ?? (string)$m->id,
                    'nombre' => trim("Dr(a). {$m->name} {$m->lastname}"),
                    'especialidad' => $specialty->name ?? $nombreEspecialidad,
                ];
            }

            return [
                'especialidad' => $nombreEspecialidad,
                'medicos' => $resultado,
            ];
        } catch (\Throwable $e) {
            Log::error('PacienteAtencionContextService::buscarMedicosPorEspecialidad error: ' . $e->getMessage());
            return [
                'especialidad' => $nombreEspecialidad,
                'medicos' => [],
            ];
        }
    }

    /**
     * Obtiene la lista de nombres de especialidades desde el modelo Specialty.
     */
    protected function obtenerEspecialidadesDisponibles(): array
    {
        try {
            if (class_exists(Specialty::class)) {
                $lista = Specialty::orderBy('name')
                    ->pluck('name')
                    ->filter()
                    ->take(10)
                    ->values()
                    ->toArray();

                if (!empty($lista)) {
                    return $lista;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('PacienteAtencionContextService: error cargando Specialty: ' . $e->getMessage());
        }

        return ['Medicina General', 'Ginecología y Obstetricia', 'Pediatría', 'Cardiología', 'Traumatología'];
    }
}
