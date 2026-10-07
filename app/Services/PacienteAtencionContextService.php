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
     * Busca al paciente en el modelo Paciente probando variantes del número telefónico.
     */
    public function buscarPacientePorTelefono(string $telefono): ?Paciente
    {
        $clean = preg_replace('/\D/', '', $telefono); // Ej: 584165800403

        if (empty($clean)) {
            return null;
        }

        $posiblesValores = [
            $telefono,
            $clean,
        ];

        // Variaciones para números en Venezuela (+58)
        if (str_starts_with($clean, '58') && strlen($clean) >= 12) {
            $nacional = '0' . substr($clean, 2); // 04165800403
            $sinPrefijo = substr($clean, 2);      // 4165800403
            $posiblesValores[] = $nacional;
            $posiblesValores[] = $sinPrefijo;
            $posiblesValores[] = '+' . $clean;
            if (strlen($nacional) === 11) {
                $posiblesValores[] = substr($nacional, 0, 4) . '-' . substr($nacional, 4); // 0416-5800403
                $posiblesValores[] = substr($nacional, 0, 4) . ' ' . substr($nacional, 4); // 0416 5800403
            }
        } elseif (str_starts_with($clean, '0') && strlen($clean) === 11) {
            $internacional = '58' . substr($clean, 1);
            $posiblesValores[] = $internacional;
            $posiblesValores[] = '+' . $internacional;
            $posiblesValores[] = substr($clean, 1);
            $posiblesValores[] = substr($clean, 0, 4) . '-' . substr($clean, 4);
        }

        $posiblesValores = array_unique(array_filter($posiblesValores));

        try {
            // 1. Búsqueda exacta por cualquiera de las variantes formateadas
            $paciente = Paciente::whereIn('telefono', $posiblesValores)->first();
            if ($paciente) {
                return $paciente;
            }

            // 2. Búsqueda por los últimos 7 dígitos locales (número de abonado en VE)
            $ultimos7 = substr($clean, -7);
            if (strlen($ultimos7) === 7) {
                $paciente = Paciente::where('telefono', 'like', "%{$ultimos7}%")->first();
                if ($paciente) {
                    return $paciente;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('PacienteAtencionContextService: error al buscar paciente: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Obtiene el contexto completo del paciente a partir de su número de teléfono.
     * Consulta Paciente, MedicoPaciente, Medico y Specialty.
     *
     * @param string $telefono
     * @return array
     */
    public function obtenerContextoPaciente(string $telefono): array
    {
        $paciente = $this->buscarPacientePorTelefono($telefono);
        $especialidades = $this->obtenerEspecialidadesDisponibles();

        // 1. Paciente NO registrado en la tabla pacientes
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

        // 2. Paciente SÍ registrado en la tabla pacientes
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
     * Genera el mensaje estructurado de WhatsApp para el primer contacto o saludo.
     */
    public function generarMensajePrimerContacto(array $contexto): string
    {
        $especialidades = $contexto['especialidades'] ?? [];

        // CASO 1: El teléfono NO existe en la tabla pacientes (Paciente Nuevo)
        if (!$contexto['existe']) {
            $msg = "¡Hola! 👋 Te damos la bienvenida a *Doctorisimo*, tu plataforma de atención médica y gestión de citas.\n\n";
            $msg .= "¿A cuál especialidad médica deseas buscar un médico para agendar tu cita?\n\n";

            foreach ($especialidades as $idx => $esp) {
                $numEmoji = $this->obtenerNumeroEmoji($idx + 1);
                $msg .= "{$numEmoji} {$esp}\n";
            }

            $msg .= "\nPor favor, responde con el número o nombre de la especialidad que necesitas. 😊";
            return $msg;
        }

        // CASO 2: El teléfono SÍ existe en la tabla pacientes
        $nombre = $contexto['nombre'];
        $medicos = $contexto['medicos_asociados'] ?? [];
        $totalMedicos = count($medicos);

        // 2.A: Si son varios médicos en MedicoPaciente
        if ($totalMedicos > 1) {
            $msg = "¡Hola, *{$nombre}*! 👋 Bienvenido a *Doctorisimo*. Es un gusto saludarte.\n\n";
            $msg .= "Vemos que en nuestro consultorio te has atendido anteriormente con los siguientes médicos:\n\n";

            foreach ($medicos as $idx => $med) {
                $numEmoji = $this->obtenerNumeroEmoji($idx + 1);
                $msg .= "{$numEmoji} *{$med['nombre']}* ({$med['especialidad']})\n";
            }

            $msg .= "\n¿A qué médico deseas referenciar para agendar tu cita?\n";
            $msg .= "*(Escribe el número o el nombre de tu doctor, o indícanos si prefieres otra especialidad)*.";
            return $msg;
        }

        // 2.B: Si es 1 solo médico en MedicoPaciente
        if ($totalMedicos === 1) {
            $med = $medicos[0];
            $msg = "¡Hola, *{$nombre}*! 👋 Bienvenido a *Doctorisimo*. Es un gusto saludarte.\n\n";
            $msg .= "Vemos que tu médico tratante registrado es el *{$med['nombre']}* ({$med['especialidad']}).\n\n";
            $msg .= "¿Deseas consultar la disponibilidad de cita con tu doctor habitual o prefieres buscar otra especialidad médica?\n\n";
            $msg .= "1️⃣ Consultar disponibilidad con {$med['nombre']}\n";
            $msg .= "2️⃣ Buscar otra especialidad médica";
            return $msg;
        }

        // 2.C: Si NO existe registro en MedicoPaciente
        $msg = "¡Hola, *{$nombre}*! 👋 Bienvenido a *Doctorisimo*. Es un gusto saludarte.\n\n";
        $msg .= "¿A cuál especialidad médica deseas buscar un médico para agendar tu cita?\n\n";

        foreach ($especialidades as $idx => $esp) {
            $numEmoji = $this->obtenerNumeroEmoji($idx + 1);
            $msg .= "{$numEmoji} {$esp}\n";
        }

        $msg .= "\nPor favor, responde con el número o nombre de la especialidad que buscas. 😊";
        return $msg;
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
    public function obtenerEspecialidadesDisponibles(): array
    {
        try {
            if (class_exists(Specialty::class)) {
                $lista = Specialty::orderBy('name')
                    ->pluck('name')
                    ->filter()
                    ->take(8)
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

    /**
     * Ayudante para emojis numéricos
     */
    protected function obtenerNumeroEmoji(int $numero): string
    {
        $emojis = [
            1 => '1️⃣',
            2 => '2️⃣',
            3 => '3️⃣',
            4 => '4️⃣',
            5 => '5️⃣',
            6 => '6️⃣',
            7 => '7️⃣',
            8 => '8️⃣',
            9 => '9️⃣',
            10 => '🔟',
        ];

        return $emojis[$numero] ?? "{$numero}.";
    }
}
