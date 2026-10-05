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

        $systemInstruction = "Eres el asistente virtual inteligente de la clínica y consultorio médico Doctorisimo.\n" .
            "Tu objetivo es atender a los pacientes por WhatsApp de forma empática, educada, clara y profesional.\n" .
            "Puedes responder dudas sobre citas, horarios, médicos y orientación general.\n" .
            "REGLAS OBLIGATORIAS:\n" .
            "1. NO diagnostiques enfermedades ni recetes tratamientos para casos graves.\n" .
            "2. En caso de síntomas de alarma o urgencias, aconseja de inmediato acudir al centro de salud más cercano.\n" .
            "3. Responde de forma concisa y amigable adaptada a WhatsApp (mensajes claros, sin párrafos excesivamente largos).\n";

        if (!empty($contexto['nombre'])) {
            $systemInstruction .= "El paciente se llama: " . $contexto['nombre'] . ".\n";
        }
        if (!empty($contexto['citas'])) {
            $systemInstruction .= "Citas del paciente: " . json_encode($contexto['citas'], JSON_UNESCAPED_UNICODE) . ".\n";
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
                'temperature' => 0.4,
                'maxOutputTokens' => 500,
            ]
        ];

        // Lista de modelos a intentar en orden de preferencia (fallback si hay 503 o sobrecarga)
        $modelosFallback = array_unique([$this->model, 'gemini-3.8-flash', 'gemini-3.5-flash', 'gemini-2.5-flash-lite']);

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

                // Si fue 503 (servidor saturado) o 429 (cuota de ese modelo), prueba el siguiente modelo
                if (in_array($status, [503, 429])) {
                    usleep(300000); // 300ms de espera antes del reintento
                    continue;
                }

            } catch (\Throwable $e) {
                Log::error("GeminiService Exception con modelo {$modeloActual}: " . $e->getMessage());
            }
        }

        return 'Hola, gracias por comunicarte con Doctorisimo. En este momento nuestros sistemas presentan alta demanda. Un asesor se comunicará contigo a la brevedad.';
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
            // La tabla pacientes sólo tiene columna 'telefono' (no 'celular')
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