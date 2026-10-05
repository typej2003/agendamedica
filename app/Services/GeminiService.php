<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiService
{
    protected string $apiKey;
    protected string $model;
    protected string $baseUrl;

    public function __construct()
    {
        $this->apiKey = config('services.gemini.api_key', '');
        $this->model = config('services.gemini.model', 'gemini-1.5-flash');
        $this->baseUrl = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent";
    }

    /**
     * Genera una respuesta médica/asistencial personalizada.
     *
     * @param string $mensajeUsuario Mensaje entrante del paciente
     * @param array $contexto Contexto del paciente/médico (nombre, citas próximas, etc.)
     * @return string
     */
    public function responderMensaje(string $mensajeUsuario, array $contexto = []): string
    {
        if (empty($this->apiKey)) {
            Log::error('GeminiService: API Key no configurada.');
            return 'En este momento no podemos procesar tu solicitud automáticamente. Un asistente se comunicará contigo a la brevedad.';
        }

        $systemInstruction = "Eres el asistente virtual inteligente de la clínica / consultorio médico. " .
            "Tu objetivo es atender a los pacientes de forma cordial, empática, clara y profesional. " .
            "Puedes responder dudas sobre citas, horarios y orientación general. " .
            "IMPORTANTE: No debes diagnosticar enfermedades ni recetar medicamentos en casos graves; en caso de emergencia médica, indica siempre que acudan al centro de urgencias más cercano.\n";

        if (!empty($contexto['nombre_paciente'])) {
            $systemInstruction .= "El paciente se llama: " . $contexto['nombre_paciente'] . ".\n";
        }
        if (!empty($contexto['citas_pendientes'])) {
            $systemInstruction .= "Información de sus próximas citas: " . json_encode($contexto['citas_pendientes'], JSON_UNESCAPED_UNICODE) . ".\n";
        }

        try {
            $response = Http::timeout(30)->post("{$this->baseUrl}?key={$this->apiKey}", [
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
                    'maxOutputTokens' => 600,
                ]
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return $data['candidates'][0]['content']['parts'][0]['text'] 
                    ?? 'Gracias por comunicarte. En breve te responderemos.';
            }

            Log::error('GeminiService: Error en respuesta de Gemini', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);
            return 'Disculpa, ocurrió un inconveniente temporal al procesar tu consulta. En breve te responderemos.';

        } catch (\Throwable $e) {
            Log::error('GeminiService Exception: ' . $e->getMessage());
            return 'Estimado paciente, hemos recibido su mensaje. Nos pondremos en contacto con usted a la brevedad.';
        }
    }

    public function generarRespuesta($mensaje, $contexto = [])
{
    $apiKey = config('services.gemini.api_key') ?? env('GEMINI_API_KEY');
    // 1. Diagnóstico: Verificar si la clave existe
    if (empty($apiKey)) {
        Log::error('GEMINI DEBUG: La API KEY está vacía o no se está leyendo.');
        return 'Lo siento, no pude procesar tu solicitud en este momento.';
    }
    $model = config('services.gemini.model', 'gemini-1.5-flash');
    $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}";
    Log::info('GEMINI DEBUG: Enviando petición a: ' . "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent");
    try {
        $response = Http::withoutVerifying() // Evita problemas de certificados SSL en local
            ->timeout(20)
            ->withHeaders([
                'Content-Type' => 'application/json',
            ])
            ->post($url, [
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [
                            ['text' => $mensaje]
                        ]
                    ]
                ]
            ]);
        // 2. Diagnóstico: Si Google responde con error (400, 403, 404, etc.)
        if (!$response->successful()) {
            Log::error('GEMINI DEBUG ERROR HTTP ' . $response->status(), [
                'body' => $response->body()
            ]);
            return 'Lo siento, no pude procesar tu solicitud en este momento.';
        }
        $data = $response->json();
        
        return $data['candidates'][0]['content']['parts'][0]['text'] 
            ?? 'Lo siento, no pude procesar tu solicitud en este momento.';
    } catch (\Throwable $e) {
        // 3. Diagnóstico: Si ocurre una excepción de red o código
        Log::error('GEMINI DEBUG EXCEPTION: ' . $e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]);
        return 'Lo siento, no pude procesar tu solicitud en este momento.';
    }
}
}