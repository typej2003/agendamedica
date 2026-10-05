<?php

namespace App\Services;

use App\Models\Cola;
use App\Models\Medico;
use App\Models\Paciente;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiService
{
    protected string $apiKey;
    protected string $model;

    public function __construct()
    {
        $this->apiKey = config('services.gemini.api_key') ?? env('GEMINI_API_KEY', '');
        $this->model  = config('services.gemini.model') ?? env('GEMINI_MODEL', 'gemini-3.8-flash');
    }

    /**
     * Procesa y genera una respuesta médica/asistencial personalizada para el paciente.
     *
     * @param string $mensajeUsuario
     * @param string|null $telefono
     * @return string
     */
    public function generarRespuesta(string $mensajeUsuario, ?string $telefono = null): string
    {
        if (empty($this->apiKey)) {
            Log::error('GeminiService: API Key no configurada o vacía.');
            return 'Hola, en este momento no podemos procesar tu solicitud automáticamente. Un asistente te responderá a la brevedad.';
        }

        // Obtener contexto del paciente si se cuenta con el teléfono
        $contexto = $telefono ? $this->obtenerContextoPaciente($telefono) : [];

        $nombreSaludo = !empty($contexto['nombre']) ? " {$contexto['nombre']}" : "";

        $systemInstruction = "Eres el asistente virtual oficial de Doctorisimo, la plataforma de gestión médica y clínica.\n" .
            "Tu objetivo es brindar una atención cálida, humana, respetuosa, clara y eficiente a través de WhatsApp.\n\n" .
            "REGLAS DE INTERACCIÓN SEGÚN EL MENSAJE:\n" .
            "1. SI EL USUARIO SALUDA (ej: 'hola', 'buenas', 'buen día', 'saludos', etc.):\n" .
            "   - Salúdalo cordialmente por su nombre si está disponible (ej: '¡Hola{$nombreSaludo}! 👋 Bienvenido a Doctorisimo').\n" .
            "   - Ofrécele tu ayuda presentando un menú claro y ordenado con viñetas o números con las opciones disponibles:\n" .
            "     ¿En qué te puedo colaborar hoy?\n" .
            "     1️⃣ Agendar o consultar una cita médica\n" .
            "     2️⃣ Conocer nuestros médicos y especialidades disponibles\n" .
            "     3️⃣ Horarios de atención y ubicación del consultorio\n" .
            "     4️⃣ Dudas sobre récipes o indicaciones médicas\n" .
            "     5️⃣ Hablar con un asistente del personal\n" .
            "   - Invítalo amablemente a escribir el número de la opción o su duda directamente.\n\n" .
            "2. SI EL USUARIO PREGUNTA POR UN SERVICIO ESPECÍFICO (ej: citas, doctores, precios, horarios, etc.):\n" .
            "   - Responde directamente y con amabilidad a lo que necesita sin abrumarlo con texto innecesario.\n" .
            "   - Si pregunta por citas y tiene citas pendientes registradas, infórmaselas amablemente.\n\n" .
            "3. REGLAS MÉDICAS Y DE SEGURIDAD OBLIGATORIAS:\n" .
            "   - NO diagnostiques enfermedades ni recetes medicamentos para situaciones delicadas o de urgencia.\n" .
            "   - Si el paciente describe síntomas graves (dolor en el pecho, dificultad para respirar, sangrado severo, etc.), indícale con prioridad acudir de urgencia al centro de salud más cercano.\n" .
            "   - Utiliza emojis apropiados para que la lectura sea amigable y visualmente atractiva en WhatsApp.\n";

        if (!empty($contexto['nombre'])) {
            $systemInstruction .= "\nContexto: El paciente registrado se llama: {$contexto['nombre']}.\n";
        }
        if (!empty($contexto['citas'])) {
            $systemInstruction .= "Citas médicas del paciente en el sistema: " . json_encode($contexto['citas'], JSON_UNESCAPED_UNICODE) . ".\n";
        }

        $payload = [
            'system_instruction' => [
                'parts' => [
                    ['text' => $systemInstruction]
                ]
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => $mensajeUsuario]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.35,
                'maxOutputTokens' => 600,
            ]
        ];

        // Lista de modelos activos comprobados
        $modelosFallback = array_unique([$this->model, 'gemini-3.8-flash', 'gemini-3.5-flash']);

        foreach ($modelosFallback as $modeloActual) {
            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$modeloActual}:generateContent";

            try {
                $response = Http::withoutVerifying()
                    ->timeout(20)
                    ->withHeaders([
                        'x-goog-api-key' => $this->apiKey,
                        'Content-Type' => 'application/json',
                    ])
                    ->post($url, $payload);

                if ($response->successful()) {
                    $data = $response->json();
                    $texto = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
                    if ($texto) {
                        return $texto;
                    }
                }

                $status = $response->status();
                Log::warning("GeminiService: Falló el modelo {$modeloActual} con HTTP {$status}. Reintentando con alternativo si existe.", [
                    'body' => $response->body()
                ]);

                usleep(250000); // 250ms de espera antes del reintento
                continue;

            } catch (\Throwable $e) {
                Log::error("GeminiService Exception con modelo {$modeloActual}: " . $e->getMessage());
            }
        }

        return "¡Hola{$nombreSaludo}! 👋 Bienvenido a Doctorisimo.\n\n¿En qué te podemos ayudar hoy?\n1️⃣ Consultar o agendar cita\n2️⃣ Especialidades y médicos\n3️⃣ Horarios de atención\n4️⃣ Hablar con un asesor";
    }

    /**
     * Busca información de perfil y citas del paciente.
     */
    protected function obtenerContextoPaciente(string $telefono): array
    {
        $contexto = [];
        $ultimosDigitos = substr(preg_replace('/\D/', '', $telefono), -8);

        if (empty($ultimosDigitos)) {
            return $contexto;
        }

        try {
            $paciente = Paciente::where('telefono', 'like', "%{$ultimosDigitos}%")->first();

            if ($paciente) {
                $contexto['nombre'] = trim("{$paciente->nombres} {$paciente->apellidos}");

                $citas = Cola::where('numhistoria', $paciente->numhistoria)
                    ->whereDate('fecha', '>=', Carbon::today()->toDateString())
                    ->orderBy('fecha', 'asc')
                    ->take(3)
                    ->get(['fecha', 'hora', 'status']);

                if ($citas->isNotEmpty()) {
                    $contexto['citas'] = $citas->toArray();
                }
            }
        } catch (\Throwable $e) {
            Log::warning('No se pudo cargar contexto del paciente: ' . $e->getMessage());
        }

        return $contexto;
    }
}