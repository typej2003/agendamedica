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
        if (empty($this->apiKey)) {
            Log::error('GeminiService: API Key no configurada o vacía.');
            return $this->generarRespuestaFallback($mensajeUsuario, $telefono);
        }

        // 1. Obtener contexto del paciente
        $contexto = $telefono
            ? $this->contextService->obtenerContextoPaciente($telefono)
            : [
                'existe' => false,
                'nombre' => null,
                'numhistoria' => null,
                'medicos_asociados' => [],
                'tiene_medicos' => false,
                'especialidades' => ['Medicina General', 'Ginecología', 'Pediatría', 'Cardiología'],
            ];

        // 2. Construir el System Instruction enriquecido
        $systemInstruction = $this->construirSystemInstruction($contexto);

        // 3. Declaración de herramientas (Tools / Function Calling)
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

        // Modelos de fallback automático
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

                    // Evaluar si Gemini invocó una Tool (Function Calling)
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

                        // Segundo turno: Enviar la respuesta de la tool a Gemini
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

                usleep(250_000); // 250 ms

            } catch (\Throwable $e) {
                Log::error("GeminiService Exception con modelo {$modeloActual}: " . $e->getMessage());
            }
        }

        // Si falla la API de Gemini, responder con el fallback enriquecido
        return $this->generarRespuestaFallback($mensajeUsuario, $telefono);
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

        $especialidadesStr = implode(', ', $contexto['especialidades']);

        if ($contexto['existe']) {
            $nombre = $contexto['nombre'];
            $prompt .= "ESTADO DEL PACIENTE: REGISTRADO.\n";
            $prompt .= "- Nombre completo: {$nombre}.\n";
            $prompt .= "- Historia clínica: #{$contexto['numhistoria']}.\n";

            $medicos = $contexto['medicos_asociados'];
            $totalMedicos = count($medicos);

            if ($totalMedicos > 1) {
                $prompt .= "- El paciente tiene antecedentes en MedicoPaciente con {$totalMedicos} médicos tratantes:\n";
                foreach ($medicos as $i => $m) {
                    $prompt .= "  " . ($i + 1) . ". {$m['nombre']} ({$m['especialidad']}) [reg_medico: {$m['reg_medico']}]\n";
                }
                $prompt .= "\nREGLAS DE ATENCIÓN:\n";
                $prompt .= "1. SALUDO INICIAL: Saluda cordialmente al paciente por su nombre y apellido ('¡Hola, {$nombre}! 👋 Bienvenido a Doctorisimo').\n";
                $prompt .= "2. INDAGAR MÉDICO: Como ha sido atendido por varios médicos, muéstrale la lista numerada de sus doctores conocidos y pregúntale a qué médico desea referenciar o agendar su consulta.\n";
                $prompt .= "3. También dale la opción de indicar si prefiere consultar una especialidad diferente.\n";
                $prompt .= "4. CONSULTA DE CUPO: Cuando el paciente elija a uno de sus médicos (por número o nombre), DEBES invocar la herramienta 'consultar_proximo_cupo_disponible' con el 'reg_medico' de ese médico.\n";
                $prompt .= "5. RESPUESTA DE CUPO: Con la respuesta devuelta por la función, infórmale el día más próximo donde le queda cupo y cuántos cupos le quedan.\n";
            } elseif ($totalMedicos === 1) {
                $m = $medicos[0];
                $prompt .= "- El paciente tiene un médico tratante registrado en MedicoPaciente:\n";
                $prompt .= "  {$m['nombre']} ({$m['especialidad']}) [reg_medico: {$m['reg_medico']}].\n";
                $prompt .= "\nREGLAS DE ATENCIÓN:\n";
                $prompt .= "1. SALUDO INICIAL: Saluda al paciente cordialmente por su nombre ('¡Hola, {$nombre}! 👋').\n";
                $prompt .= "2. CONFIRMAR MÉDICO: Pregúntale si desea consultar la disponibilidad de cita con su médico habitual ({$m['nombre']}) o si desea consultar otra especialidad médica.\n";
                $prompt .= "3. CONSULTA DE CUPO: Si confirma que desea cita con ese doctor, DEBES llamar a 'consultar_proximo_cupo_disponible' con reg_medico '{$m['reg_medico']}'.\n";
                $prompt .= "4. Informa el día más cercano disponible y los cupos libres según el resultado.\n";
            } else {
                // Paciente existe pero no tiene médicos en MedicoPaciente
                $prompt .= "- El paciente está registrado pero NO tiene médicos previos en MedicoPaciente.\n";
                $prompt .= "\nREGLAS DE ATENCIÓN:\n";
                $prompt .= "1. SALUDO INICIAL: Salúdalo cordialmente por su nombre ('¡Hola, {$nombre}! 👋 Bienvenido a Doctorisimo').\n";
                $prompt .= "2. PREGUNTAR ESPECIALIDAD: Como no tiene médico previo registrado, pregúntale a cuál especialidad médica desea buscar un médico para agendar su cita.\n";
                $prompt .= "   Menciónale nuestras especialidades disponibles: {$especialidadesStr}.\n";
                $prompt .= "3. Al indicar una especialidad, llama a 'buscar_medicos_por_especialidad' y luego consulta el cupo más próximo con 'consultar_proximo_cupo_disponible'.\n";
            }
        } else {
            // Paciente NO registrado
            $prompt .= "ESTADO DEL PACIENTE: NO REGISTRADO (Primer contacto / Nuevo usuario).\n";
            $prompt .= "\nREGLAS DE ATENCIÓN:\n";
            $prompt .= "1. SALUDO INICIAL: Saluda de forma general y cordial, dándole la bienvenida a Doctorisimo ('¡Hola! 👋 Bienvenido a Doctorisimo').\n";
            $prompt .= "2. PREGUNTAR ESPECIALIDAD: Pregúntale a cuál especialidad médica desea buscar un médico para agendar su cita.\n";
            $prompt .= "   Muestra las opciones de especialidades disponibles: {$especialidadesStr}.\n";
            $prompt .= "3. Una vez indicada la especialidad o el médico, usa las herramientas para verificar doctores y consultar el día más próximo con cupos en 'Cola'.\n";
        }

        $prompt .= "\nREGLAS GENERALES:\n";
        $prompt .= "- Sé empático, claro, conciso y usa viñetas/emojis moderados.\n";
        $prompt .= "- NUNCA inventes fechas ni cupos; utiliza SIEMPRE el resultado de 'consultar_proximo_cupo_disponible'.\n";
        $prompt .= "- NO diagnostiques ni mediques. Ante emergencias graves, indica acudir a urgencias.\n";

        return $prompt;
    }

    /**
     * Respuesta de fallback si los modelos de Gemini no responden
     */
    protected function generarRespuestaFallback(string $mensajeUsuario, ?string $telefono = null): string
    {
        $contexto = $telefono
            ? $this->contextService->obtenerContextoPaciente($telefono)
            : ['existe' => false];

        if (!empty($contexto['existe'])) {
            $nombre = $contexto['nombre'];
            $medicos = $contexto['medicos_asociados'] ?? [];

            if (count($medicos) > 1) {
                $salida = "¡Hola, {$nombre}! 👋 Bienvenido a Doctorisimo.\n\n"
                    . "Vemos que anteriormente te has atendido con nuestros siguientes especialistas:\n";
                foreach ($medicos as $i => $m) {
                    $salida .= ($i + 1) . "️⃣ {$m['nombre']} ({$m['especialidad']})\n";
                }
                $salida .= "\n¿Con cuál de ellos deseas agendar tu cita? Escribe el número o el nombre del doctor.";
                return $salida;
            }

            if (count($medicos) === 1) {
                $m = $medicos[0];
                return "¡Hola, {$nombre}! 👋 Bienvenido a Doctorisimo.\n\n"
                    . "¿Deseas agendar una cita con tu médico habitual, {$m['nombre']} ({$m['especialidad']}), o prefieres consultar otra especialidad?";
            }

            $especialidadesStr = implode("\n- ", $contexto['especialidades'] ?? []);
            return "¡Hola, {$nombre}! 👋 Bienvenido a Doctorisimo.\n\n"
                . "¿A cuál especialidad médica deseas acudir para buscar un médico y agendar tu cita?\n- {$especialidadesStr}";
        }

        return "¡Hola! 👋 Bienvenido a Doctorisimo.\n\n"
            . "¿En qué especialidad médica deseas buscar un médico para agendar tu cita?\n"
            . "1️⃣ Medicina General\n"
            . "2️⃣ Ginecología y Obstetricia\n"
            . "3️⃣ Pediatría\n"
            . "4️⃣ Cardiología\n\n"
            . "Por favor, escribe la opción o la especialidad que buscas.";
    }
}