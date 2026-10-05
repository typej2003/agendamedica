<?php

namespace App\Services;

use App\Models\Cola;
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
     */
    public function generarRespuesta(string $mensajeUsuario, ?string $telefono = null): string
    {
        if (empty($this->apiKey)) {
            Log::error('GeminiService: API Key no configurada o vacía.');
            return 'Hola, en este momento no podemos procesar tu solicitud automáticamente. Un asistente te responderá a la brevedad.';
        }

        // Obtener contexto del paciente si se cuenta con el teléfono
        $contexto = $telefono ? $this->obtenerContextoPaciente($telefono) : [];

        $pacienteEncontrado = !empty($contexto['nombre']);
        $nombreSaludo = $pacienteEncontrado ? " {$contexto['nombre']}" : '';

        // Obtener especialidades reales desde la base de datos
        $especialidades    = $this->obtenerEspecialidades();
        $listaEspecialidades = !empty($especialidades)
            ? implode(', ', $especialidades)
            : 'Medicina General, Ginecología, Pediatría, Cardiología';

        // ----------------------------------------------------------------
        // System instruction diferenciada según si el paciente existe o no
        // ----------------------------------------------------------------
        if ($pacienteEncontrado) {
            $systemInstruction =
                "Eres el asistente virtual oficial de Doctorisimo, la plataforma de gestión médica y clínica.\n"
                . "Tu objetivo es brindar una atención cálida, humana, respetuosa, clara y eficiente a través de WhatsApp.\n\n"
                . "CONTEXTO DEL PACIENTE:\n"
                . "- El paciente registrado se llama: {$contexto['nombre']}.\n";

            if (!empty($contexto['citas'])) {
                $systemInstruction .= '- Tiene las siguientes citas médicas próximas registradas en el sistema: '
                    . json_encode($contexto['citas'], JSON_UNESCAPED_UNICODE) . ".\n";
            } else {
                $systemInstruction .= "- No tiene citas médicas próximas registradas.\n";
            }

            $systemInstruction .=
                "\nREGLAS DE INTERACCIÓN:\n"
                . "1. SI EL PACIENTE SALUDA: Salúdalo cordialmente por su nombre "
                . "(ej: '¡Hola, {$contexto['nombre']}! 👋 Bienvenido a Doctorisimo').\n"
                . "   Si tiene citas pendientes, menciónaselas brevemente. Luego ofrécele este menú:\n"
                . "     ¿En qué te puedo colaborar hoy?\n"
                . "     1️⃣ Consultar o agendar una cita médica\n"
                . "     2️⃣ Conocer las especialidades disponibles\n"
                . "     3️⃣ Horarios de atención y ubicación\n"
                . "     4️⃣ Dudas sobre récipes o indicaciones médicas\n"
                . "     5️⃣ Hablar con un asistente del personal\n\n"
                . "2. SI PREGUNTA POR ESPECIALIDADES: Menciona la lista real: {$listaEspecialidades}.\n\n"
                . "3. REGLAS MÉDICAS OBLIGATORIAS:\n"
                . "   - NO diagnostiques enfermedades ni recetes medicamentos.\n"
                . "   - Ante síntomas graves (dolor en el pecho, dificultad para respirar, sangrado severo, etc.) "
                . "indica acudir de urgencia al centro de salud más cercano.\n"
                . "   - Usa emojis apropiados para WhatsApp.\n";
        } else {
            $systemInstruction =
                "Eres el asistente virtual oficial de Doctorisimo, la plataforma de gestión médica y clínica.\n"
                . "Tu objetivo es brindar una atención cálida, humana, respetuosa, clara y eficiente a través de WhatsApp.\n\n"
                . "CONTEXTO: El número de teléfono del usuario NO está registrado como paciente en el sistema.\n\n"
                . "REGLAS DE INTERACCIÓN:\n"
                . "1. SI EL USUARIO SALUDA: Salúdalo cordialmente de forma genérica "
                . "(ej: '¡Hola! 👋 Bienvenido a Doctorisimo'). Luego ofrécele este menú:\n"
                . "     ¿En qué te puedo colaborar hoy?\n"
                . "     1️⃣ Preguntar por la Agenda (citas disponibles)\n"
                . "     2️⃣ Preguntar por nuestras Especialidades médicas\n"
                . "   Invítalo a escribir el número de la opción o su consulta directamente.\n\n"
                . "2. SI ELIGE LA OPCIÓN 2 O PREGUNTA POR ESPECIALIDADES:\n"
                . "   Responde con la lista real de especialidades disponibles en Doctorisimo: {$listaEspecialidades}.\n"
                . "   Invítalo a indicar cuál le interesa para obtener más información o agendar.\n\n"
                . "3. SI ELIGE LA OPCIÓN 1 O PREGUNTA POR AGENDA/CITAS:\n"
                . "   Indícale que para consultar disponibilidad necesita proporcionar su nombre completo "
                . "y la especialidad o médico de interés, y que un asistente le confirmará los horarios.\n\n"
                . "4. REGLAS MÉDICAS OBLIGATORIAS:\n"
                . "   - NO diagnostiques enfermedades ni recetes medicamentos.\n"
                . "   - Ante síntomas graves (dolor en el pecho, dificultad para respirar, sangrado severo, etc.) "
                . "indica acudir de urgencia al centro de salud más cercano.\n"
                . "   - Usa emojis apropiados para WhatsApp.\n";
        }

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
            'generationConfig' => [
                'temperature'     => 0.35,
                'maxOutputTokens' => 600,
            ],
        ];

        // Modelos a probar en orden (fallback automático)
        $modelosFallback = array_unique([$this->model, 'gemini-3.8-flash', 'gemini-3.5-flash']);

        foreach ($modelosFallback as $modeloActual) {
            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$modeloActual}:generateContent";

            try {
                $response = Http::withoutVerifying()
                    ->timeout(20)
                    ->withHeaders([
                        'x-goog-api-key' => $this->apiKey,
                        'Content-Type'   => 'application/json',
                    ])
                    ->post($url, $payload);

                if ($response->successful()) {
                    $texto = $response->json('candidates.0.content.parts.0.text');
                    if ($texto) {
                        return $texto;
                    }
                }

                Log::warning("GeminiService: modelo {$modeloActual} respondió HTTP {$response->status()}.", [
                    'body' => $response->body(),
                ]);

                usleep(250_000); // 250 ms antes del siguiente intento

            } catch (\Throwable $e) {
                Log::error("GeminiService Exception con modelo {$modeloActual}: " . $e->getMessage());
            }
        }

        // Fallback de texto si todos los modelos fallan
        if ($pacienteEncontrado) {
            return "¡Hola{$nombreSaludo}! 👋 Bienvenido a Doctorisimo.\n\n"
                . "¿En qué te podemos ayudar hoy?\n"
                . "1️⃣ Consultar o agendar cita\n"
                . "2️⃣ Especialidades y médicos\n"
                . "3️⃣ Horarios de atención\n"
                . "4️⃣ Hablar con un asesor";
        }

        return "¡Hola! 👋 Bienvenido a Doctorisimo.\n\n"
            . "¿En qué te puedo colaborar hoy?\n"
            . "1️⃣ Preguntar por la Agenda (citas disponibles)\n"
            . "2️⃣ Preguntar por nuestras Especialidades médicas\n\n"
            . "Escribe el número de tu opción o tu consulta directamente.";
    }

    /**
     * Obtiene la lista de especialidades reales.
     * Intenta primero con el modelo Specialty (tabla nueva); si falla o está vacío,
     * cae en el modelo Especialidad del sistema legado.
     */
    protected function obtenerEspecialidades(): array
    {
        // 1) Tabla specialties (sistema nuevo)
        try {
            if (class_exists(\App\Models\Specialty::class)) {
                $lista = \App\Models\Specialty::select('name')
                    ->orderBy('name')
                    ->get()
                    ->pluck('name')
                    ->filter()
                    ->values()
                    ->toArray();

                if (!empty($lista)) {
                    return $lista;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('GeminiService: error al cargar Specialty: ' . $e->getMessage());
        }

        // 2) Tabla especial (legado PowerBuilder)
        try {
            if (class_exists(\App\Models\Especialidad::class)) {
                $lista = \App\Models\Especialidad::select('especialidad')
                    ->distinct()
                    ->orderBy('especialidad')
                    ->get()
                    ->pluck('especialidad')
                    ->filter()
                    ->values()
                    ->toArray();

                if (!empty($lista)) {
                    return $lista;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('GeminiService: error al cargar Especialidad legado: ' . $e->getMessage());
        }

        return [];
    }

    /**
     * Busca el perfil y las próximas citas del paciente por número de teléfono.
     */
    protected function obtenerContextoPaciente(string $telefono): array
    {
        $contexto      = [];
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