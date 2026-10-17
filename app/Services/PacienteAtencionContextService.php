<?php

namespace App\Services;

use App\Models\Cola;
use App\Models\Evolucion;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
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
                'telefono' => $telefono,
                'nombre' => null,
                'numhistoria' => null,
                'medicos_asociados' => [],
                'tiene_medicos' => false,
                'especialidades' => $especialidades,
            ];
        }

        // 2. Paciente SÍ registrado en la tabla pacientes
        $nombreCompleto = trim("{$paciente->nombres} {$paciente->apellidos}");
        $numhistoria = null;
        try {
            $numhistoria = $paciente->numhistoria ?? $paciente->historias()->value('numhistoria');
        } catch (\Throwable $e) {
            // Si la columna numhistoria no existe en la tabla pacientes
        }

        // Consultar el modelo relacional MedicoPaciente
        $medicosAsociados = [];
        try {
            $relaciones = MedicoPaciente::where(function ($q) use ($paciente, $numhistoria) {
                $q->where('paciente_id', $paciente->id);
                if (!empty($numhistoria)) {
                    $q->orWhere('numhistoria', $numhistoria);
                }
            })->get();
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
            'telefono' => $telefono,
            'paciente_id' => $paciente->id,
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
     * Resuelve una instancia del modelo Medico a partir de reg_medico, id o nombre/apellido.
     */
    public function resolverMedico(string $criterio): ?Medico
    {
        $criterio = trim($criterio);
        if ($criterio === '') {
            return null;
        }

        // 1. Búsqueda exacta por reg_medico o ID numérico
        $medico = Medico::with(['specialties', 'registro'])
            ->where('reg_medico', $criterio)
            ->orWhere('id', $criterio)
            ->first();

        if ($medico) {
            return $medico;
        }

        // 2. Búsqueda mediante la tabla MedicoRegistro
        $reg = MedicoRegistro::where('reg_medico', $criterio)->first();
        if ($reg && $reg->medico) {
            return $reg->medico->load(['specialties', 'registro']);
        }

        // 3. Limpiar prefijos de cortesía habituales
        $limpio = trim(preg_replace('/^(dr\.|dra\.|dr|dra|doctor|doctora)\s+/i', '', $criterio));

        // 4. Búsqueda por coincidencia de nombre, apellido o nombre completo
        $partes = array_values(array_filter(explode(' ', $limpio)));

        return Medico::with(['specialties', 'registro'])
            ->where(function ($q) use ($limpio, $partes) {
                $q->where('name', 'like', "%{$limpio}%")
                  ->orWhere('lastname', 'like', "%{$limpio}%");

                if (count($partes) >= 2) {
                    $primer = $partes[0];
                    $ultimo = $partes[count($partes) - 1];
                    $q->orWhere(function ($sub) use ($primer, $ultimo) {
                        $sub->where('name', 'like', "%{$primer}%")
                            ->where('lastname', 'like', "%{$ultimo}%");
                    });
                }
            })
            ->first();
    }

    /**
     * Consulta en la tabla evolucion la dirección del consultorio y los datos de contacto del médico.
     */
    public function obtenerDatosConsultorio(string $criterioMedico): array
    {
        $medico = $this->resolverMedico($criterioMedico);
        $regMedico = $medico?->regMedicoPrincipal() ?? $criterioMedico;

        try {
            $evolucion = Evolucion::where('reg_medico', $regMedico)->first();

            if (!$evolucion && $medico && $medico->email) {
                $evolucion = Evolucion::where('correo_med', $medico->email)->first();
            }

            $nombreMedico = $medico ? $medico->nombre_mostrar : "Dr(a). {$criterioMedico}";
            $especialidad = $evolucion?->especialidad
                ?: ($medico?->specialties->pluck('name')->first() ?? $medico?->biography ?? 'Consulta Médica');

            if (!$evolucion) {
                return [
                    'encontrado' => false,
                    'medico' => $nombreMedico,
                    'especialidad' => $especialidad,
                    'reg_medico' => $regMedico,
                    'mensaje' => "No se encontraron datos registrados de la ubicación del consultorio para {$nombreMedico}.",
                ];
            }

            // Construir dirección limpia combinando linea_1, linea_2 y linea_3
            $lineas = array_filter([
                trim((string) $evolucion->linea_1),
                trim((string) $evolucion->linea_2),
                trim((string) $evolucion->linea_3),
            ]);
            $direccion = !empty($lineas) ? implode(', ', $lineas) : 'Dirección no especificada';

            return [
                'encontrado' => true,
                'medico' => $nombreMedico,
                'especialidad' => $especialidad,
                'reg_medico' => $regMedico,
                'consultorio' => $evolucion->consultorio ?: 'Consultorio principal',
                'ciudad' => $evolucion->ciudad ?: 'No especificada',
                'direccion' => $direccion,
                'telefono' => $evolucion->telefono ?: ($medico?->phone ?: 'No especificado'),
                'correo' => $evolucion->correo_med ?: ($medico?->email ?: 'No especificado'),
            ];
        } catch (\Throwable $e) {
            Log::error('PacienteAtencionContextService::obtenerDatosConsultorio error: ' . $e->getMessage());
            return [
                'encontrado' => false,
                'error' => 'Error al consultar los datos del consultorio en la base de datos.',
            ];
        }
    }

    /**
     * Consulta en la tabla evolucion los días y horarios de atención del médico.
     */
    public function obtenerHorariosAtencion(string $criterioMedico): array
    {
        $medico = $this->resolverMedico($criterioMedico);
        $regMedico = $medico?->regMedicoPrincipal() ?? $criterioMedico;

        try {
            $evolucion = Evolucion::where('reg_medico', $regMedico)->first();

            if (!$evolucion && $medico && $medico->email) {
                $evolucion = Evolucion::where('correo_med', $medico->email)->first();
            }

            $nombreMedico = $medico ? $medico->nombre_mostrar : "Dr(a). {$criterioMedico}";
            $especialidad = $evolucion?->especialidad
                ?: ($medico?->specialties->pluck('name')->first() ?? 'Consulta Médica');

            if (!$evolucion) {
                return [
                    'encontrado' => false,
                    'medico' => $nombreMedico,
                    'especialidad' => $especialidad,
                    'reg_medico' => $regMedico,
                    'mensaje' => "No se registraron horarios de atención específicos en el sistema para {$nombreMedico}.",
                ];
            }

            $diasConfigurados = [];
            $mapaDias = [
                'lunes'     => ['nombre' => 'Lunes',     'inicio' => $evolucion->lunes_i,     'fin' => $evolucion->lunes_f],
                'martes'    => ['nombre' => 'Martes',    'inicio' => $evolucion->martes_i,    'fin' => $evolucion->martes_f],
                'miercoles' => ['nombre' => 'Miércoles', 'inicio' => $evolucion->miercoles_i, 'fin' => $evolucion->miercoles_f],
                'jueves'    => ['nombre' => 'Jueves',    'inicio' => $evolucion->jueves_i,    'fin' => $evolucion->jueves_f],
                'viernes'   => ['nombre' => 'Viernes',   'inicio' => $evolucion->vienes_i ?? $evolucion->viernes_i ?? null, 'fin' => $evolucion->viernes_f],
                'sabado'    => ['nombre' => 'Sábado',    'inicio' => $evolucion->sabado_i,    'fin' => $evolucion->sabado_f],
                'domingo'   => ['nombre' => 'Domingo',   'inicio' => $evolucion->domingo_i,   'fin' => $evolucion->domigo_f ?? $evolucion->domingo_f ?? null],
            ];

            foreach ($mapaDias as $campoDia => $info) {
                $activo = $evolucion->{$campoDia};
                $esActivo = !empty($activo) && !in_array(strtolower(trim((string)$activo)), ['0', 'n', 'no', 'false', 'none'], true);

                if ($esActivo) {
                    $horario = 'Horario no especificado';
                    if (!empty($info['inicio']) && !empty($info['fin'])) {
                        $horario = "{$info['inicio']} a {$info['fin']}";
                    } elseif (!empty($info['inicio'])) {
                        $horario = "Desde las {$info['inicio']}";
                    }
                    $diasConfigurados[] = "{$info['nombre']}: {$horario}";
                }
            }

            $citaPreviaBool = !empty($evolucion->cita_previa) && !in_array(strtolower(trim((string)$evolucion->cita_previa)), ['0', 'n', 'no', 'false'], true);
            $modalidad = $citaPreviaBool ? 'Por cita previa' : 'Por orden de llegada';

            return [
                'encontrado' => true,
                'medico' => $nombreMedico,
                'especialidad' => $especialidad,
                'reg_medico' => $regMedico,
                'dias_atencion' => $diasConfigurados,
                'modalidad' => $modalidad,
                'tiempo_por_paciente' => $evolucion->tiempo_paci ? "{$evolucion->tiempo_paci} minutos aprox." : null,
                'cupos_maximos_diarios' => $evolucion->cantidad_paciente ?: self::LIMITE_CUPOS_DIARIOS,
            ];

        } catch (\Throwable $e) {
            Log::error('PacienteAtencionContextService::obtenerHorariosAtencion error: ' . $e->getMessage());
            return [
                'encontrado' => false,
                'error' => 'Error al consultar los horarios de atención.',
            ];
        }
    }

    /**
     * Retorna una ficha integral del médico combinando identidad, consultorio (evolucion) y horarios.
     */
    public function obtenerInformacionMedico(string $criterioMedico): array
    {
        $consultorio = $this->obtenerDatosConsultorio($criterioMedico);
        $horarios = $this->obtenerHorariosAtencion($criterioMedico);

        return [
            'medico' => $consultorio['medico'] ?? $horarios['medico'] ?? $criterioMedico,
            'especialidad' => $consultorio['especialidad'] ?? $horarios['especialidad'] ?? 'Consulta',
            'reg_medico' => $consultorio['reg_medico'] ?? $horarios['reg_medico'] ?? $criterioMedico,
            'consultorio' => $consultorio,
            'horarios' => $horarios,
        ];
    }

    /**
     * Consulta el modelo Cola para encontrar el día más próximo donde le queda cupo al médico
     * buscando por su campo reg_medico o nombre.
     *
     * @param string $regMedico
     * @param int $diasMaximos
     * @return array
     */
    public function consultarProximoDiaDisponible(string $regMedico, int $diasMaximos = 30): array
    {
        try {
            $medico = $this->resolverMedico($regMedico);
            $regFinal = $medico?->regMedicoPrincipal() ?? $regMedico;
            $nombreMedico = $medico ? $medico->nombre_mostrar : "Médico (Registro: {$regMedico})";
            $especialidad = $medico ? ($medico->specialties->pluck('name')->first() ?? 'Medicina General') : 'Consulta';

            // Consultar límite configurado en evolucion si existe
            $limiteDiario = self::LIMITE_CUPOS_DIARIOS;
            try {
                $ev = Evolucion::where('reg_medico', $regFinal)->first();
                if ($ev && !empty($ev->cantidad_paciente) && (int)$ev->cantidad_paciente > 0) {
                    $limiteDiario = (int)$ev->cantidad_paciente;
                }
            } catch (\Throwable $e) {
                // Mantener límite por defecto
            }

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

                if ($citasOcupadas < $limiteDiario) {
                    $cuposRestantes = $limiteDiario - $citasOcupadas;
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

    /**
     * Reserva e inserta un registro de cita en la tabla 'cola'.
     */
    public function reservarCitaEnCola(string $telefono, string $criterioMedico, ?string $fecha = null, ?string $motivo = null): array
    {
        try {
            // 1. Identificar al paciente por su número de teléfono
            $paciente = $this->buscarPacientePorTelefono($telefono);

            if (!$paciente) {
                // Registrar temporalmente al paciente nuevo por WhatsApp
                $paciente = Paciente::create([
                    'nombres' => 'Paciente',
                    'apellidos' => 'WhatsApp',
                    'telefono' => $telefono,
                ]);
            }

            $nombrePaciente = trim("{$paciente->nombres} {$paciente->apellidos}");
            if ($nombrePaciente === 'Paciente WhatsApp' || empty($nombrePaciente)) {
                $nombrePaciente = "Paciente ({$telefono})";
            }

            $pacienteId = $paciente->id;
            $numhistoria = null;
            try {
                $numhistoria = $paciente->numhistoria ?? $paciente->historias()->value('numhistoria');
            } catch (\Throwable $e) {}

            // 2. Resolver al médico
            $medico = $this->resolverMedico($criterioMedico);
            if (!$medico) {
                return [
                    'exito' => false,
                    'mensaje' => "No se pudo identificar al médico '{$criterioMedico}' para registrar la cita.",
                ];
            }

            $regMedico = $medico->regMedicoPrincipal() ?? $medico->reg_medico;
            $nombreMedico = $medico->nombre_mostrar;
            $especialidad = $medico->specialties->pluck('name')->first() ?? $medico->biography ?? 'Consulta Médica';

            // Validar si el paciente ya tiene una cita activa pendiente (atendido = 0) con este médico
            $citaPendiente = $this->obtenerCitaPendienteConMedico($paciente, $medico);
            if ($citaPendiente) {
                return [
                    'exito' => false,
                    'ya_tiene_cita' => true,
                    'cita' => $citaPendiente['cita'],
                    'mensaje' => $citaPendiente['mensaje'],
                    'mensaje_confirmacion' => $citaPendiente['mensaje'],
                ];
            }

            // 3. Determinar y validar la fecha
            if (empty($fecha)) {
                $disponible = $this->consultarProximoDiaDisponible($regMedico);
                if (!$disponible['disponible']) {
                    return [
                        'exito' => false,
                        'mensaje' => "El {$nombreMedico} no cuenta con cupos disponibles en los próximos días.",
                    ];
                }
                $fechaCita = Carbon::parse($disponible['fecha']);
            } else {
                $fechaCita = Carbon::parse($fecha);
                if ($fechaCita->isPast() && !$fechaCita->isToday()) {
                    return [
                        'exito' => false,
                        'mensaje' => "La fecha solicitada ({$fecha}) ya ha pasado. Por favor indica una fecha actual o futura.",
                    ];
                }
                if ($fechaCita->isSunday()) {
                    return [
                        'exito' => false,
                        'mensaje' => "Los días domingos no hay atención de consulta. Por favor selecciona un día de lunes a sábado.",
                    ];
                }
            }

            $fechaStr = $fechaCita->toDateString();

            // 4. Verificar límite de cupos para ese día
            $limiteDiario = self::LIMITE_CUPOS_DIARIOS;
            try {
                $ev = Evolucion::where('reg_medico', $regMedico)->first();
                if ($ev && !empty($ev->cantidad_paciente) && (int)$ev->cantidad_paciente > 0) {
                    $limiteDiario = (int)$ev->cantidad_paciente;
                }
            } catch (\Throwable $e) {}

            $citasExistentes = Cola::where('reg_medico', $regMedico)
                ->whereDate('fecha', $fechaStr)
                ->count();

            if ($citasExistentes >= $limiteDiario) {
                return [
                    'exito' => false,
                    'mensaje' => "Lamentablemente se han agotado los cupos para el {$nombreMedico} en la fecha {$fechaCita->format('d/m/Y')}. ¿Deseas consultar el próximo día disponible?",
                ];
            }

            // 5. Asignar orden de turno, hora y clave de evolución del médico
            $claveMedico = $medico->claveDeEvolucion((array) $regMedico) ?? (int) $medico->id;
            $turno = $citasExistentes + 1;
            $horaCarbon = Carbon::createFromTime(8, 0)->addMinutes(($turno - 1) * 20);
            $horaIni = $horaCarbon->format('H:i');
            $horaFin = $horaCarbon->copy()->addMinutes(20)->format('H:i');
            $turnoLetra = $horaCarbon->hour < 12 ? 'M' : 'T';

            // 6. Insertar registro en la tabla cola
            $nuevaCita = Cola::create([
                'reg_medico' => $regMedico,
                'fecha' => $fechaStr,
                'numhistoria' => $numhistoria,
                'paciente_sinhistoria_id' => $numhistoria ? null : $pacienteId,
                'numorden' => $turno,
                'atendido' => 0,
                'estado' => Cola::ESTADO_CONFIRMADA_PACIENTE,
                'turno' => $turnoLetra,
                'motivo' => $motivo ?: 'Cita agendada vía WhatsApp',
                'hora_ini' => $horaIni,
                'hora_fin' => $horaFin,
                'tiempo' => 20,
                'tipo' => 'CONSULTA',
                'medico' => $claveMedico,
            ]);

            // Registrar rastro en sync_changes si la clase está disponible
            if (class_exists(\App\Sync\RegistroDeCambios::class)) {
                try {
                    \App\Sync\RegistroDeCambios::registrarOperacion('cola', (int) $nuevaCita->id, $regMedico, 'created', null, null, now(), 'whatsapp');
                } catch (\Throwable $e) {
                    Log::warning('No se pudo registrar operacion de sync para cola: ' . $e->getMessage());
                }
            }

            // 7. Obtener dirección del consultorio para adjuntarla
            $consultorio = $this->obtenerDatosConsultorio($regMedico);
            $fechaFormateada = ucfirst($fechaCita->locale('es')->isoFormat('dddd D [de] MMMM [de] YYYY'));

            $datosConfirmacion = [
                'cita_id' => $nuevaCita->id,
                'paciente' => $nombrePaciente,
                'medico' => $nombreMedico,
                'especialidad' => $especialidad,
                'fecha' => $fechaFormateada,
                'hora_estimada' => $horaCarbon->format('h:i A'),
                'turno' => $turno,
                'consultorio' => $consultorio['consultorio'] ?? 'Consultorio principal',
                'direccion' => $consultorio['direccion'] ?? 'Dirección no especificada',
            ];

            return [
                'exito' => true,
                'datos' => $datosConfirmacion,
                'mensaje_confirmacion' => $this->generarMensajeCitaRegistrada($datosConfirmacion),
            ];

        } catch (\Throwable $e) {
            Log::error('PacienteAtencionContextService::reservarCitaEnCola error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return [
                'exito' => false,
                'error' => 'No fue posible registrar la cita en este momento: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Genera la plantilla oficial del mensaje de confirmación de cita registrada.
     */
    public function generarMensajeCitaRegistrada(array $datos): string
    {
        $msg = "✅ *¡CITA REGISTRADA CON ÉXITO!*\n\n";
        $msg .= "👤 *Paciente:* {$datos['paciente']}\n";
        $msg .= "👨‍⚕️ *Médico:* {$datos['medico']} ({$datos['especialidad']})\n";
        $msg .= "📅 *Fecha:* {$datos['fecha']}\n";
        $msg .= "🎫 *Turno asignado:* #{$datos['turno']}\n";
        $msg .= "⏰ *Hora sugerida:* {$datos['hora_estimada']}\n";
        $msg .= "📍 *Consultorio:* {$datos['consultorio']}\n";
        $msg .= "🏢 *Dirección:* {$datos['direccion']}\n\n";
        $msg .= "ℹ️ *Nota:* Te recomendamos llegar 15 minutos antes de tu hora estimada.\n\n";
        $msg .= "¿Deseas consultar alguna otra información o requerimiento para tu consulta? 😊";

        return $msg;
    }

    /**
     * Busca si un paciente tiene una cita activa pendiente (atendido = 0) con un médico determinado.
     */
    public function obtenerCitaPendienteConMedico($paciente, $criterioMedico): ?array
    {
        if (!$paciente) {
            return null;
        }

        $medico = $criterioMedico instanceof Medico ? $criterioMedico : $this->resolverMedico((string) $criterioMedico);
        if (!$medico) {
            return null;
        }

        $regMedico = $medico->regMedicoPrincipal() ?? $medico->reg_medico;
        $registrosDoctor = array_filter(array_unique(array_merge(
            array_filter([$regMedico, $medico->reg_medico]),
            $medico->registros()->pluck('reg_medico')->all()
        )));
        $claveMedico = $medico->claveDeEvolucion((array) $regMedico) ?? (int) $medico->id;

        $historias = [];
        if (!empty($paciente->numhistoria)) {
            $historias[] = $paciente->numhistoria;
        }
        try {
            $fromHistorias = $paciente->historias()->pluck('numhistoria')->all();
            $historias = array_merge($historias, $fromHistorias);
        } catch (\Throwable $e) {}
        try {
            $fromPivot = \DB::table('medico_pacientes')
                ->where('paciente_id', $paciente->id)
                ->whereNotNull('numhistoria')
                ->pluck('numhistoria')
                ->all();
            $historias = array_merge($historias, $fromPivot);
        } catch (\Throwable $e) {}
        $historias = array_values(array_unique(array_filter($historias)));

        $citaExistente = Cola::where('atendido', 0)
            ->where(function ($qDoctor) use ($registrosDoctor, $claveMedico, $medico) {
                if (!empty($registrosDoctor)) {
                    $qDoctor->whereIn('reg_medico', $registrosDoctor);
                }
                if ($claveMedico) {
                    $qDoctor->orWhere('medico', $claveMedico);
                }
                if (!empty($medico->id)) {
                    $qDoctor->orWhere('medico', $medico->id);
                }
            })
            ->where(function ($qPac) use ($paciente, $historias) {
                $qPac->where('paciente_sinhistoria_id', $paciente->id);
                if (!empty($historias)) {
                    $qPac->orWhereIn('numhistoria', $historias);
                }
            })
            ->orderByDesc('fecha')
            ->first();

        if (!$citaExistente) {
            return null;
        }

        $nombrePaciente = trim("{$paciente->nombres} {$paciente->apellidos}");
        if (empty($nombrePaciente) || $nombrePaciente === 'Paciente WhatsApp') {
            $nombrePaciente = $paciente->telefono ? "Paciente ({$paciente->telefono})" : 'Paciente';
        }

        return [
            'cita' => $citaExistente,
            'mensaje' => $this->generarMensajeCitaExistente($citaExistente, $nombrePaciente, $medico),
        ];
    }

    /**
     * Busca cita pendiente de atención (atendido = 0) identificando al paciente por su número de teléfono.
     */
    public function obtenerCitaPendienteConMedicoPorTelefono(string $telefono, $criterioMedico): ?array
    {
        $paciente = $this->buscarPacientePorTelefono($telefono);
        if (!$paciente) {
            return null;
        }

        return $this->obtenerCitaPendienteConMedico($paciente, $criterioMedico);
    }

    /**
     * Genera el mensaje informativo de cita existente cuando el paciente ya tiene una cita activa (atendido = 0).
     */
    public function generarMensajeCitaExistente(Cola $cita, string $nombrePaciente, Medico $medico): string
    {
        $regMedico = $medico->regMedicoPrincipal() ?? $medico->reg_medico;
        $consultorio = $this->obtenerDatosConsultorio($regMedico);
        $especialidad = $medico->specialties->pluck('name')->first() ?? $medico->biography ?? 'Consulta Médica';

        $fechaCarbon = Carbon::parse($cita->fecha);
        $fechaFormateada = ucfirst($fechaCarbon->locale('es')->isoFormat('dddd D [de] MMMM [de] YYYY'));

        $horaStr = '';
        if (!empty($cita->hora_ini)) {
            try {
                $horaStr = Carbon::createFromFormat('H:i', substr($cita->hora_ini, 0, 5))->format('h:i A');
            } catch (\Throwable $e) {
                $horaStr = $cita->hora_ini;
            }
        } else {
            $horaStr = '08:00 AM';
        }

        $turno = $cita->numorden ?? 1;

        $msg = "⚠️ *YA TIENES UNA CITA PROGRAMADA*\n\n";
        $msg .= "Estimado(a) *{$nombrePaciente}*, verificamos que ya cuentas con una cita pendiente de atención con el/la *{$medico->nombre_mostrar}* ({$especialidad}):\n\n";
        $msg .= "📅 *Fecha:* {$fechaFormateada}\n";
        $msg .= "🎫 *Turno asignado:* #{$turno}\n";
        $msg .= "⏰ *Hora sugerida:* {$horaStr}\n";
        if (!empty($cita->motivo)) {
            $msg .= "📝 *Motivo:* {$cita->motivo}\n";
        }
        $msg .= "📍 *Consultorio:* " . ($consultorio['consultorio'] ?? 'Consultorio principal') . "\n";
        $msg .= "🏢 *Dirección:* " . ($consultorio['direccion'] ?? 'Dirección no especificada') . "\n\n";
        $msg .= "ℹ️ *Nota:* No es necesario registrar una nueva cita. Si deseas realizar algún cambio o necesitas información adicional, con gusto te atenderemos. 😊";

        return $msg;
    }
}
