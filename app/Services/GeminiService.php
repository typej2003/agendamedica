<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiService
{
    protected string $apiKey;
    protected string $model;
    protected PacienteAtencionContextService $contextService;

    public function __construct(PacienteAtencionContextService $contextService)
    {
        $this->apiKey = config('services.gemini.api_key') ?? env('GEMINI_API_KEY', '');
        $this->model  = config('services.gemini.model') ?? env('GEMINI_MODEL', 'gemini-3.8-flash');
        $this->contextService = $contextService;
    }

    /**
     * Procesa y genera una respuesta personalizada para el paciente considerando
     * su existencia en Paciente, sus médicos en MedicoPaciente, las especialidades
     * en Specialty y la disponibilidad de cupos en Cola.
     */
    public function generarRespuesta(string $mensajeUsuario, ?string $telefono = null): string
    {
        $cleanTel = $telefono ? preg_replace('/\D/', '', $telefono) : null;

        // 0. Si se proporciona teléfono, verificar si el paciente está en proceso de registro o si no existe en Paciente
        if ($telefono) {
            $respuestaRegistro = $this->contextService->gestionarRegistroPaciente($telefono, $mensajeUsuario);
            if ($respuestaRegistro !== null) {
                return $respuestaRegistro;
            }
        }

        // 1. Obtener contexto del paciente por su teléfono
        $contexto = $telefono
            ? $this->contextService->obtenerContextoPaciente($telefono)
            : [
                'existe' => false,
                'telefono' => null,
                'nombre' => null,
                'numhistoria' => null,
                'medicos_asociados' => [],
                'tiene_medicos' => false,
                'especialidades' => $this->contextService->obtenerEspecialidadesDisponibles(),
            ];

        // 2. Si el usuario confirma directamente una cita ofrecida previamente ("sí", "confirmo", "resérvala", "1")
        if (!empty($contexto['telefono']) && $this->esConfirmacionDeReserva($mensajeUsuario, $contexto['telefono'])) {
            $pendiente = Cache::get("wa_pendiente_{$contexto['telefono']}") ?? ($cleanTel ? Cache::get("wa_pendiente_{$cleanTel}") : null);
            if ($pendiente) {
                $resultado = $this->contextService->reservarCitaEnCola(
                    $contexto['telefono'],
                    $pendiente['reg_medico'],
                    $pendiente['fecha'] ?? null
                );
                Cache::forget("wa_pendiente_{$contexto['telefono']}");
                if ($cleanTel) {
                    Cache::forget("wa_pendiente_{$cleanTel}");
                }
                if (!empty($resultado['exito']) && !empty($resultado['mensaje_confirmacion'])) {
                    return $resultado['mensaje_confirmacion'];
                }
                if (!empty($resultado['mensaje'])) {
                    return $resultado['mensaje'];
                }
                if (!empty($resultado['error'])) {
                    return $resultado['error'];
                }
            }
        }

        // 3. Manejo directo si el usuario seleccionó un médico de una especialidad previamente consultada
        $respuestaMedicoEspecialidad = $this->resolverOpcionMedicoDeEspecialidad($mensajeUsuario, $contexto);
        if ($respuestaMedicoEspecialidad !== null) {
            return $respuestaMedicoEspecialidad;
        }

        // 4. Manejo directo si el usuario responde con el número de opción del médico mostrado en su menú habitual
        $respuestaDirectaOpcion = $this->resolverOpcionDirecta($mensajeUsuario, $contexto);
        if ($respuestaDirectaOpcion !== null) {
            return $respuestaDirectaOpcion;
        }

        // 5. Si el mensaje es un saludo o comando de inicio ("Hola", "Buenas tardes", etc.)
        if ($this->esSaludoOPrimerMensaje($mensajeUsuario)) {
            return $this->contextService->generarMensajePrimerContacto($contexto);
        }

        // 6. Si el usuario seleccionó una especialidad médica (por número de opción o por nombre)
        $respuestaEspecialidad = $this->resolverSeleccionEspecialidad($mensajeUsuario, $contexto);
        if ($respuestaEspecialidad !== null) {
            return $respuestaEspecialidad;
        }

        // 7. Si el usuario solicita directamente un médico ("o si solicita directamente un medico")
        $respuestaMedicoDirecto = $this->resolverSolicitudDirectaMedico($mensajeUsuario, $contexto);
        if ($respuestaMedicoDirecto !== null) {
            return $respuestaMedicoDirecto;
        }

        // 8. Si es una consulta conversacional, procesar con Gemini AI y Function Calling
        if (empty($this->apiKey)) {
            Log::error('GeminiService: API Key no configurada o vacía.');
            return $this->contextService->generarMensajePrimerContacto($contexto);
        }

        return $this->procesarConGeminiAI($mensajeUsuario, $contexto);
    }

    /**
     * Evalúa si el mensaje recibido corresponde a un saludo o primer contacto.
     */
    protected function esSaludoOPrimerMensaje(string $mensaje): bool
    {
        $limpio = mb_strtolower(trim(preg_replace('/[^\p{L}\p{N}\s]/u', '', $mensaje)));

        if (is_numeric($limpio)) {
            return false;
        }

        $saludos = [
            'hola', 'ola', 'buenas', 'buenos dias', 'buenos días', 'buenas tardes',
            'buenas noches', 'buen dia', 'buen día', 'saludos', 'hola buen dia',
            'hola buenas', 'hola buenas tardes', 'hola buenos dias', 'menu', 'inicio',
            'empezar', 'start', 'ayuda', 'hi', 'hello'
        ];

        return in_array($limpio, $saludos) || in_array($limpio, ['hola!', 'hola.', 'buenas!']);
    }

    /**
     * Resuelve si el usuario seleccionó una opción numérica directa correspondiente
     * a uno de sus médicos habituales mostrados en el menú previo.
     */
    protected function resolverOpcionDirecta(string $mensajeUsuario, array $contexto): ?string
    {
        $trim = trim($mensajeUsuario);
        $medicos = $contexto['medicos_asociados'] ?? [];

        if (is_numeric($trim)) {
            $indice = (int)$trim - 1;
            if (isset($medicos[$indice])) {
                $medicoElegido = $medicos[$indice];

                // Si el paciente ya tiene una cita activa pendiente (atendido = 0) con ese médico, mostrar mensaje de la cita
                if (!empty($contexto['telefono'])) {
                    $citaActiva = $this->contextService->obtenerCitaPendienteConMedicoPorTelefono($contexto['telefono'], $medicoElegido['reg_medico']);
                    if ($citaActiva) {
                        return $citaActiva['mensaje'];
                    }
                }

                $disponibilidad = $this->contextService->consultarProximoDiaDisponible($medicoElegido['reg_medico']);

                if (!empty($disponibilidad['disponible'])) {
                    if (!empty($contexto['telefono'])) {
                        $cleanTel = preg_replace('/\D/', '', $contexto['telefono']);
                        $datosPendientes = [
                            'reg_medico' => $medicoElegido['reg_medico'],
                            'fecha' => $disponibilidad['fecha'],
                        ];
                        Cache::put("wa_pendiente_{$contexto['telefono']}", $datosPendientes, now()->addMinutes(30));
                        Cache::put("wa_pendiente_{$cleanTel}", $datosPendientes, now()->addMinutes(30));
                    }

                    return "¡Perfecto! Hemos consultado la agenda del *{$medicoElegido['nombre']}* ({$medicoElegido['especialidad']}):\n\n"
                        . "📅 *Día más próximo disponible:* {$disponibilidad['fecha_formateada']}\n"
                        . "🎫 *Cupos disponibles:* {$disponibilidad['cupos_disponibles']}\n\n"
                        . "¿Deseas que reservemos tu cita para esta fecha?";
                } else {
                    return "Hemos verificado la agenda del *{$medicoElegido['nombre']}*, pero lamentablemente no cuenta con cupos en los próximos 30 días.\n\n"
                        . "¿Deseas consultar la disponibilidad de otra especialidad o médico?";
                }
            }
        }

        return null;
    }

    /**
     * Resuelve si el usuario seleccionó un médico de la lista de una especialidad consultada previamente.
     */
    protected function resolverOpcionMedicoDeEspecialidad(string $mensajeUsuario, array $contexto): ?string
    {
        $telefono = $contexto['telefono'] ?? null;
        if (!$telefono) {
            return null;
        }

        $cleanTel = preg_replace('/\D/', '', $telefono);
        $medicos = Cache::get("wa_medicos_especialidad_{$telefono}") ?? Cache::get("wa_medicos_especialidad_{$cleanTel}");

        if (empty($medicos) || !is_array($medicos)) {
            return null;
        }

        $trim = trim($mensajeUsuario);
        $medicoElegido = null;

        if (is_numeric($trim)) {
            $indice = (int)$trim - 1;
            if (isset($medicos[$indice])) {
                $medicoElegido = $medicos[$indice];
            }
        } else {
            foreach ($medicos as $m) {
                if (mb_stripos($m['nombre'], $trim) !== false || mb_stripos($trim, $m['nombre']) !== false) {
                    $medicoElegido = $m;
                    break;
                }
            }
        }

        if (!$medicoElegido) {
            return null;
        }

        // Limpiar la lista de médicos de la especialidad para evitar colisiones posteriores
        Cache::forget("wa_medicos_especialidad_{$telefono}");
        Cache::forget("wa_medicos_especialidad_{$cleanTel}");

        // Si el paciente ya tiene una cita activa pendiente (atendido = 0) con ese médico, mostrar mensaje de la cita
        $citaActiva = $this->contextService->obtenerCitaPendienteConMedicoPorTelefono($telefono, $medicoElegido['reg_medico']);
        if ($citaActiva) {
            return $citaActiva['mensaje'];
        }

        $disponibilidad = $this->contextService->consultarProximoDiaDisponible($medicoElegido['reg_medico']);

        if (!empty($disponibilidad['disponible'])) {
            $datosPendientes = [
                'reg_medico' => $medicoElegido['reg_medico'],
                'fecha' => $disponibilidad['fecha'],
            ];
            Cache::put("wa_pendiente_{$telefono}", $datosPendientes, now()->addMinutes(30));
            Cache::put("wa_pendiente_{$cleanTel}", $datosPendientes, now()->addMinutes(30));

            return "¡Perfecto! Hemos consultado la agenda del *{$medicoElegido['nombre']}* ({$medicoElegido['especialidad']}):\n\n"
                . "📅 *Día más próximo disponible:* {$disponibilidad['fecha_formateada']}\n"
                . "🎫 *Cupos disponibles:* {$disponibilidad['cupos_disponibles']}\n\n"
                . "¿Deseas que reservemos tu cita para esta fecha?";
        } else {
            return "Hemos verificado la agenda del *{$medicoElegido['nombre']}*, pero lamentablemente no cuenta con cupos en los próximos 30 días.\n\n"
                . "¿Deseas consultar la disponibilidad de otra especialidad o médico?";
        }
    }

    /**
     * Resuelve si el usuario seleccionó una especialidad médica (por número o por nombre)
     * y presenta los médicos disponibles en esa especialidad.
     */
    protected function resolverSeleccionEspecialidad(string $mensajeUsuario, array $contexto): ?string
    {
        $telefono = $contexto['telefono'] ?? null;
        $resultado = $this->contextService->resolverSeleccionEspecialidad($mensajeUsuario, $telefono);

        if (!$resultado) {
            return null;
        }

        $esp = $resultado['especialidad'];
        $medicos = $resultado['medicos'];

        if (empty($medicos)) {
            return "Actualmente no disponemos de médicos con cupos registrados para la especialidad de *{$esp}*.\n\n"
                . "¿Deseas consultar otra especialidad o solicitar un médico directamente?";
        }

        if ($telefono) {
            $cleanTel = preg_replace('/\D/', '', $telefono);
            Cache::put("wa_medicos_especialidad_{$telefono}", $medicos, now()->addMinutes(30));
            Cache::put("wa_medicos_especialidad_{$cleanTel}", $medicos, now()->addMinutes(30));
        }

        $msg = "👨‍⚕️ *Médicos disponibles en {$esp}:*\n\n";
        foreach ($medicos as $idx => $med) {
            $numEmoji = $this->contextService->obtenerNumeroEmoji($idx + 1);
            $msg .= "{$numEmoji} *{$med['nombre']}*\n";
        }

        $msg .= "\n¿Con cuál médico deseas agendar tu cita?\n"
            . "*(Escribe el número o el nombre de tu doctor, o indícanos si prefieres otra especialidad)*.";

        return $msg;
    }

    /**
     * Resuelve si el usuario solicita directamente a un médico específico en su mensaje.
     */
    protected function resolverSolicitudDirectaMedico(string $mensajeUsuario, array $contexto): ?string
    {
        $infoMedico = $this->contextService->identificarSolicitudDirectaMedico($mensajeUsuario);
        if (!$infoMedico || empty($infoMedico['solicitado'])) {
            return null;
        }

        // Si el médico solicitado NO está registrado en la clínica
        if (empty($infoMedico['registrado']) || empty($infoMedico['medico'])) {
            $nombreBuscado = $infoMedico['nombre_solicitado'] ?? 'el doctor solicitado';
            return "No encontramos registrado(a) en nuestra clínica al *Dr(a). {$nombreBuscado}*.\n\n"
                . "¿Deseas consultar nuestras especialidades disponibles o verificar con otro médico?";
        }

        $medico = $infoMedico['medico'];
        $telefono = $contexto['telefono'] ?? null;
        $regMedico = $medico->regMedicoPrincipal() ?? $medico->reg_medico;

        // Si el paciente ya tiene una cita activa pendiente (atendido = 0) con ese médico
        if ($telefono) {
            $citaActiva = $this->contextService->obtenerCitaPendienteConMedicoPorTelefono($telefono, $regMedico);
            if ($citaActiva) {
                return $citaActiva['mensaje'];
            }
        }

        $disponibilidad = $this->contextService->consultarProximoDiaDisponible($regMedico);
        $especialidad = $medico->specialties->pluck('name')->first() ?? $medico->biography ?? 'Consulta Médica';

        if (!empty($disponibilidad['disponible'])) {
            if ($telefono) {
                $cleanTel = preg_replace('/\D/', '', $telefono);
                $datosPendientes = [
                    'reg_medico' => $regMedico,
                    'fecha' => $disponibilidad['fecha'],
                ];
                Cache::put("wa_pendiente_{$telefono}", $datosPendientes, now()->addMinutes(30));
                Cache::put("wa_pendiente_{$cleanTel}", $datosPendientes, now()->addMinutes(30));
            }

            return "¡Perfecto! Hemos consultado la agenda del *{$medico->nombre_mostrar}* ({$especialidad}):\n\n"
                . "📅 *Día más próximo disponible:* {$disponibilidad['fecha_formateada']}\n"
                . "🎫 *Cupos disponibles:* {$disponibilidad['cupos_disponibles']}\n\n"
                . "¿Deseas que reservemos tu cita para esta fecha?";
        } else {
            return "Hemos verificado la agenda del *{$medico->nombre_mostrar}*, pero lamentablemente no cuenta con cupos en los próximos 30 días.\n\n"
                . "¿Deseas consultar la disponibilidad de otra especialidad o médico?";
        }
    }

    /**
     * Evalúa si el mensaje del usuario corresponde a una confirmación directa de reserva.
     */
    protected function esConfirmacionDeReserva(string $mensaje, ?string $telefono): bool
    {
        if (!$telefono) {
            return false;
        }

        $cleanTel = preg_replace('/\D/', '', $telefono);
        if (!Cache::has("wa_pendiente_{$telefono}") && !Cache::has("wa_pendiente_{$cleanTel}")) {
            return false;
        }

        $limpio = mb_strtolower(trim(preg_replace('/[^\p{L}\p{N}\s]/u', '', $mensaje)));

        // Si incluye negación o cancelación, no es confirmación
        if (preg_match('/\b(no|cancelar|rechazar|otra|otro)\b/u', $limpio)) {
            return false;
        }

        if (in_array($limpio, ['1', 'si', 'sí', 'ok', 'dale', 'de acuerdo', 'claro', 'por favor', 'porfa', 'correcto', 'adelante', 'bien', 'reserva', 'reservar', 'agendar', 'agenda'], true)) {
            return true;
        }

        return (bool) preg_match('/\b(si|sí|confirmo|confirmar|confirmado|reserva|reservar|resérvala|reservala|agenda|agendar|agendala|agéndala|apartar|aparta|claro|adelante|correcto)\b/u', $limpio);
    }

    /**
     * Procesa con la API de Gemini integrando Function Calling (Tools).
     */
    protected function procesarConGeminiAI(string $mensajeUsuario, array $contexto): string
    {
        $systemInstruction = $this->construirSystemInstruction($contexto);

        $tools = [
            [
                'function_declarations' => [
                    [
                        'name' => 'consultar_proximo_cupo_disponible',
                        'description' => 'Consulta en la tabla Cola el día más próximo a partir de hoy en que le queda cupo disponible a un médico dado su reg_medico o nombre.',
                        'parameters' => [
                            'type' => 'OBJECT',
                            'properties' => [
                                'reg_medico' => [
                                    'type' => 'STRING',
                                    'description' => 'El código de registro profesional o nombre del médico.'
                                ]
                            ],
                            'required' => ['reg_medico']
                        ]
                    ],
                    [
                        'name' => 'buscar_medicos_por_especialidad',
                        'description' => 'Busca los médicos y sus reg_medico asociados a una especialidad médica determinada.',
                        'parameters' => [
                            'type' => 'OBJECT',
                            'properties' => [
                                'especialidad' => [
                                    'type' => 'STRING',
                                    'description' => 'Nombre de la especialidad médica deseada.'
                                ]
                            ],
                            'required' => ['especialidad']
                        ]
                    ],
                    [
                        'name' => 'consultar_datos_consultorio',
                        'description' => 'Consulta en la tabla evolucion la dirección física del consultorio, referencias, ciudad, consultorio y teléfono de contacto de un médico.',
                        'parameters' => [
                            'type' => 'OBJECT',
                            'properties' => [
                                'reg_medico' => [
                                    'type' => 'STRING',
                                    'description' => 'El reg_medico o nombre del doctor a consultar.'
                                ]
                            ],
                            'required' => ['reg_medico']
                        ]
                    ],
                    [
                        'name' => 'consultar_horarios_atencion',
                        'description' => 'Consulta en la tabla evolucion los días que atiende el médico, su horario de entrada y salida, y si es por orden de llegada o cita previa.',
                        'parameters' => [
                            'type' => 'OBJECT',
                            'properties' => [
                                'reg_medico' => [
                                    'type' => 'STRING',
                                    'description' => 'El reg_medico o nombre del doctor a consultar.'
                                ]
                            ],
                            'required' => ['reg_medico']
                        ]
                    ],
                    [
                        'name' => 'consultar_especialidades_disponibles',
                        'description' => 'Obtiene la lista de especialidades médicas disponibles en la clínica.',
                        'parameters' => [
                            'type' => 'OBJECT',
                            'properties' => new \stdClass(),
                        ]
                    ],
                    [
                        'name' => 'consultar_informacion_medico',
                        'description' => 'Obtiene la información integral de un médico: su especialidad, dirección del consultorio y sus horarios de atención.',
                        'parameters' => [
                            'type' => 'OBJECT',
                            'properties' => [
                                'medico' => [
                                    'type' => 'STRING',
                                    'description' => 'Nombre o código reg_medico del médico.'
                                ]
                            ],
                            'required' => ['medico']
                        ]
                    ],
                    [
                        'name' => 'reservar_cita',
                        'description' => 'Registra e inserta una cita en la tabla Cola para el paciente con el médico en la fecha seleccionada. Retorna la confirmación oficial con el turno y datos del consultorio.',
                        'parameters' => [
                            'type' => 'OBJECT',
                            'properties' => [
                                'reg_medico' => [
                                    'type' => 'STRING',
                                    'description' => 'El código reg_medico o nombre del médico con quien se reserva la cita.'
                                ],
                                'fecha' => [
                                    'type' => 'STRING',
                                    'description' => 'Fecha para la cita en formato YYYY-MM-DD. Si no se indica, se toma el próximo día disponible.'
                                ],
                                'motivo' => [
                                    'type' => 'STRING',
                                    'description' => 'Motivo de la consulta expresado por el paciente (opcional).'
                                ]
                            ],
                            'required' => ['reg_medico']
                        ]
                    ]
                ]
            ]
        ];

        $payload = [
            'system_instruction' => [
                'parts' => [['text' => $systemInstruction]],
            ],
            'contents' => [
                [
                    'role'  => 'user',
                    'parts' => [['text' => $mensajeUsuario]],
                ],
            ],
            'tools' => $tools,
            'generationConfig' => [
                'temperature'     => 0.35,
                'maxOutputTokens' => 700,
            ],
        ];

        $modelosFallback = array_unique([$this->model, 'gemini-3.8-flash', 'gemini-3.5-flash', 'gemini-1.5-flash']);

        foreach ($modelosFallback as $modeloActual) {
            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$modeloActual}:generateContent";

            try {
                $response = Http::withoutVerifying()
                    ->timeout(25)
                    ->withHeaders([
                        'x-goog-api-key' => $this->apiKey,
                        'Content-Type'   => 'application/json',
                    ])
                    ->post($url, $payload);

                if ($response->successful()) {
                    $candidate = $response->json('candidates.0.content.parts.0');

                    // Evaluar si Gemini solicitó ejecutar una Tool (Function Calling)
                    if (isset($candidate['functionCall'])) {
                        $functionCall = $candidate['functionCall'];
                        $functionName = $functionCall['name'];
                        $args = $functionCall['args'] ?? [];

                        $resultadoTool = [];

                        if ($functionName === 'consultar_proximo_cupo_disponible') {
                            $regMedico = $args['reg_medico'] ?? '';
                            if (!empty($contexto['telefono'])) {
                                $citaActiva = $this->contextService->obtenerCitaPendienteConMedicoPorTelefono($contexto['telefono'], $regMedico);
                                if ($citaActiva) {
                                    return $citaActiva['mensaje'];
                                }
                            }
                            $resultadoTool = $this->contextService->consultarProximoDiaDisponible($regMedico);
                        } elseif ($functionName === 'buscar_medicos_por_especialidad') {
                            $esp = $args['especialidad'] ?? '';
                            $resultadoTool = $this->contextService->buscarMedicosPorEspecialidad($esp);
                        } elseif ($functionName === 'consultar_datos_consultorio') {
                            $regMedico = $args['reg_medico'] ?? '';
                            $resultadoTool = $this->contextService->obtenerDatosConsultorio($regMedico);
                        } elseif ($functionName === 'consultar_horarios_atencion') {
                            $regMedico = $args['reg_medico'] ?? '';
                            $resultadoTool = $this->contextService->obtenerHorariosAtencion($regMedico);
                        } elseif ($functionName === 'consultar_especialidades_disponibles') {
                            $resultadoTool = [
                                'especialidades' => $this->contextService->obtenerEspecialidadesDisponibles()
                            ];
                        } elseif ($functionName === 'consultar_informacion_medico') {
                            $medico = $args['medico'] ?? '';
                            $resultadoTool = $this->contextService->obtenerInformacionMedico($medico);
                        } elseif ($functionName === 'reservar_cita') {
                            $regMedico = $args['reg_medico'] ?? '';
                            $fecha = $args['fecha'] ?? null;
                            $motivo = $args['motivo'] ?? null;
                            $resultadoTool = $this->contextService->reservarCitaEnCola(
                                $contexto['telefono'] ?? '',
                                $regMedico,
                                $fecha,
                                $motivo
                            );
                            if (!empty($resultadoTool['exito']) && !empty($resultadoTool['mensaje_confirmacion'])) {
                                return $resultadoTool['mensaje_confirmacion'];
                            }
                            if (!empty($resultadoTool['mensaje'])) {
                                return $resultadoTool['mensaje'];
                            }
                        }

                        // Segundo turno para que Gemini redacte la respuesta con los datos de Cola
                        $followUpPayload = [
                            'system_instruction' => [
                                'parts' => [['text' => $systemInstruction]],
                            ],
                            'contents' => [
                                ['role' => 'user', 'parts' => [['text' => $mensajeUsuario]]],
                                ['role' => 'model', 'parts' => [$candidate]],
                                [
                                    'role' => 'function',
                                    'parts' => [
                                        [
                                            'functionResponse' => [
                                                'name' => $functionName,
                                                'response' => $resultadoTool
                                            ]
                                        ]
                                    ]
                                ]
                            ],
                            'generationConfig' => [
                                'temperature'     => 0.35,
                                'maxOutputTokens' => 700,
                            ],
                        ];

                        $followUpResponse = Http::withoutVerifying()
                            ->timeout(25)
                            ->withHeaders([
                                'x-goog-api-key' => $this->apiKey,
                                'Content-Type'   => 'application/json',
                            ])
                            ->post($url, $followUpPayload);

                        if ($followUpResponse->successful()) {
                            $textoFinal = $followUpResponse->json('candidates.0.content.parts.0.text');
                            if ($textoFinal) {
                                return $textoFinal;
                            }
                        }
                    }

                    $textoRespuesta = $response->json('candidates.0.content.parts.0.text');
                    if ($textoRespuesta) {
                        return $textoRespuesta;
                    }
                }

                Log::warning("GeminiService: modelo {$modeloActual} respondió HTTP {$response->status()}.", [
                    'body' => $response->body(),
                ]);

                usleep(250_000);

            } catch (\Throwable $e) {
                Log::error("GeminiService Exception con modelo {$modeloActual}: " . $e->getMessage());
            }
        }

        return $this->contextService->generarMensajePrimerContacto($contexto);
    }

    /**
     * Construye las instrucciones de comportamiento según el paciente, sus médicos y especialidades.
     */
    protected function construirSystemInstruction(array $contexto): string
    {
        $hoy = Carbon::now()->locale('es')->isoFormat('dddd D [de] MMMM [de] YYYY');

        $prompt = "Eres el asistente virtual oficial de Doctorisimo, la plataforma de gestión médica y clínica.\n";
        $prompt .= "Tu objetivo es brindar una atención cálida, humana, profesional, clara y eficiente por WhatsApp.\n";
        $prompt .= "Hoy es: {$hoy}.\n\n";

        $especialidadesStr = implode(', ', $contexto['especialidades'] ?? []);

        if ($contexto['existe']) {
            $nombre = $contexto['nombre'];
            $prompt .= "ESTADO DEL PACIENTE: REGISTRADO EN EL SISTEMA.\n";
            $prompt .= "- Nombre completo: {$nombre}.\n";
            $prompt .= "- Historia clínica: #{$contexto['numhistoria']}.\n";

            $medicos = $contexto['medicos_asociados'] ?? [];
            $totalMedicos = count($medicos);

            if ($totalMedicos > 1) {
                $prompt .= "- El paciente ha sido atendido en MedicoPaciente por {$totalMedicos} médicos:\n";
                foreach ($medicos as $i => $m) {
                    $prompt .= "  " . ($i + 1) . ". {$m['nombre']} ({$m['especialidad']}) [reg_medico: {$m['reg_medico']}]\n";
                }
                $prompt .= "\nREGLAS DE ATENCIÓN:\n";
                $prompt .= "1. Saluda cordialmente al paciente por su nombre y apellido ('¡Hola, {$nombre}! 👋').\n";
                $prompt .= "2. Pregúntale a qué médico desea referenciar o agendar su cita (muestra la lista numerada).\n";
                $prompt .= "3. Cuando el paciente elija o mencione a un médico, DEBES invocar 'consultar_proximo_cupo_disponible' con el 'reg_medico' de ese médico.\n";
                $prompt .= "4. Informa el día más próximo donde le queda cupo en Cola y los cupos restantes.\n";
            } elseif ($totalMedicos === 1) {
                $m = $medicos[0];
                $prompt .= "- El paciente tiene un médico habitual en MedicoPaciente:\n";
                $prompt .= "  {$m['nombre']} ({$m['especialidad']}) [reg_medico: {$m['reg_medico']}].\n";
                $prompt .= "\nREGLAS DE ATENCIÓN:\n";
                $prompt .= "1. Saluda al paciente cordialmente por su nombre ('¡Hola, {$nombre}! 👋').\n";
                $prompt .= "2. Pregúntale si desea cita con su médico habitual ({$m['nombre']}) o si desea consultar otra especialidad médica.\n";
                $prompt .= "3. Si confirma que desea cita con ese doctor, DEBES invocar 'consultar_proximo_cupo_disponible' con reg_medico '{$m['reg_medico']}'.\n";
                $prompt .= "4. Informa el día más próximo disponible en Cola y los cupos libres.\n";
            } else {
                $prompt .= "- El paciente está registrado pero NO tiene médicos previos en MedicoPaciente.\n";
                $prompt .= "\nREGLAS DE ATENCIÓN:\n";
                $prompt .= "1. Salúdalo por su nombre: '¡Hola, {$nombre}! 👋'.\n";
                $prompt .= "2. Pregúntale a cuál especialidad médica desea buscar un médico para agendar su cita.\n";
                $prompt .= "   Especialidades disponibles: {$especialidadesStr}.\n";
                $prompt .= "3. Al indicar la especialidad, llama a 'buscar_medicos_por_especialidad' y luego a 'consultar_proximo_cupo_disponible'.\n";
            }
        } else {
            $prompt .= "ESTADO DEL PACIENTE: NO REGISTRADO (Paciente nuevo / primer contacto).\n";
            $prompt .= "\nREGLAS DE ATENCIÓN:\n";
            $prompt .= "1. Saluda cordialmente dándole la bienvenida a Doctorisimo.\n";
            $prompt .= "2. Pregúntale a cuál especialidad médica desea buscar un médico para agendar su cita.\n";
            $prompt .= "   Especialidades disponibles: {$especialidadesStr}.\n";
            $prompt .= "3. Al indicar la especialidad o médico, consulta los cupos en Cola con 'consultar_proximo_cupo_disponible'.\n";
        }

        $prompt .= "\nREGLAS DE CONSULTA Y HERRAMIENTAS (TOOLS):\n";
        $prompt .= "- Si el paciente CONFIRMA O SOLICITA RESERVAR, AGENDAR O APARTAR UNA CITA (o dice 'sí', 'reserva', 'agéndala', etc.): DEBES invocar OBLIGATORIAMENTE la herramienta 'reservar_cita'.\n";
        $prompt .= "- Si el paciente pregunta por la DIRECCIÓN, UBICACIÓN FÍSICA, CIUDAD O TELÉFONO del consultorio de un médico: DEBES invocar la herramienta 'consultar_datos_consultorio'.\n";
        $prompt .= "- Si el paciente pregunta por los HORARIOS, DÍAS DE ATENCIÓN o MODALIDAD (orden de llegada o cita previa): DEBES invocar 'consultar_horarios_atencion'.\n";
        $prompt .= "- Si el paciente pregunta por CUPOS, TURNOS O DISPONIBILIDAD DE FECHAS para una cita: DEBES invocar 'consultar_proximo_cupo_disponible'.\n";
        $prompt .= "- Si el paciente pregunta qué ESPECIALIDADES MÉDICAS hay disponibles: informa las especialidades registradas o invoca 'consultar_especialidades_disponibles'.\n";
        $prompt .= "- Si el paciente pide INFORMACIÓN COMPLETA sobre un médico: invoca 'consultar_informacion_medico'.\n";

        $prompt .= "\nREGLAS GENERALES:\n";
        $prompt .= "- Sé conciso, empático y usa formato de WhatsApp (negritas con asteriscos, viñetas limpias, emojis moderados).\n";
        $prompt .= "- NUNCA inventes direcciones, horarios ni cupos; utiliza SIEMPRE los datos devueltos por las herramientas de la base de datos.\n";

        return $prompt;
    }
}