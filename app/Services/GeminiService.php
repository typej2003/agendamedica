<?php

namespace App\Services;

use Carbon\Carbon;
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
        // 1. Obtener contexto del paciente por su teléfono
        $contexto = $telefono
            ? $this->contextService->obtenerContextoPaciente($telefono)
            : [
                'existe' => false,
                'nombre' => null,
                'numhistoria' => null,
                'medicos_asociados' => [],
                'tiene_medicos' => false,
                'especialidades' => $this->contextService->obtenerEspecialidadesDisponibles(),
            ];

        // 2. Manejo directo si el usuario responde con el número de opción del médico mostrado
        $respuestaDirectaOpcion = $this->resolverOpcionDirecta($mensajeUsuario, $contexto);
        if ($respuestaDirectaOpcion !== null) {
            return $respuestaDirectaOpcion;
        }

        // 3. Si el mensaje es un saludo o la primera petición, responder según el flujo exacto requerido
        if ($this->esSaludoOPrimerMensaje($mensajeUsuario)) {
            return $this->contextService->generarMensajePrimerContacto($contexto);
        }

        // 4. Si es una consulta conversacional, procesar con Gemini AI y Function Calling
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
                $disponibilidad = $this->contextService->consultarProximoDiaDisponible($medicoElegido['reg_medico']);

                if (!empty($disponibilidad['disponible'])) {
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
                        'description' => 'Consulta en la tabla Cola el día más próximo a partir de hoy en que le queda cupo disponible a un médico dado su reg_medico.',
                        'parameters' => [
                            'type' => 'OBJECT',
                            'properties' => [
                                'reg_medico' => [
                                    'type' => 'STRING',
                                    'description' => 'El código de registro profesional del médico (reg_medico).'
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
                            $resultadoTool = $this->contextService->consultarProximoDiaDisponible($regMedico);
                        } elseif ($functionName === 'buscar_medicos_por_especialidad') {
                            $esp = $args['especialidad'] ?? '';
                            $resultadoTool = $this->contextService->buscarMedicosPorEspecialidad($esp);
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

        $prompt .= "\nREGLAS GENERALES:\n";
        $prompt .= "- Sé conciso, empático y usa formato de WhatsApp (negritas con asteriscos, emojis moderados).\n";
        $prompt .= "- NUNCA inventes fechas ni cupos; utiliza SIEMPRE el resultado de 'consultar_proximo_cupo_disponible'.\n";

        return $prompt;
    }
}