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
    protected string $baseUrl;

    public function __construct()
    {
        $this->apiKey = config('services.gemini.api_key') ?? env('GEMINI_API_KEY', '');
        $this->model  = config('services.gemini.model') ?? env('GEMINI_MODEL', 'gemini-3.8-flash');
        $this->baseUrl = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent";
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

        try {
            $response = Http::withoutVerifying()
                ->timeout(25)
                ->withHeaders([
                    'x-goog-api-key' => $this->apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post($this->baseUrl, $payload);

            if (!$response->successful()) {
                Log::error('GeminiService HTTP ' . $response->status(), [
                    'body' => $response->body()
                ]);
                return 'Disculpa, ocurrió un inconveniente al procesar tu consulta. En breve te responderemos.';
            }

            $data = $response->json();
            return $data['candidates'][0]['content']['parts'][0]['text'] 
                ?? 'Gracias por comunicarte con nosotros. En breve te responderemos.';

        } catch (\Throwable $e) {
            Log::error('GeminiService Exception: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            return 'Disculpa, no pude procesar tu mensaje en este momento. Te contactaremos a la brevedad.';
        }
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
            $paciente = Paciente::where('telefono', 'like', "%{$ultimosDigitos}%")
                ->orWhere('celular', 'like', "%{$ultimosDigitos}%")
                ->first();

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