<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class WhatsAppAIService
{
    protected string $apiKey;

    public function __construct()
    {
        $this->apiKey = config('services.openai.key') ?: env('OPENAI_API_KEY', '');
    }

    /**
     * Procesa el mensaje del paciente con la IA y responde según la disponibilidad en la DB.
     */
    public function responderMensaje(string $telefono, string $mensajeCliente): string
    {
        if (empty($this->apiKey)) {
            Log::error('OpenAI API Key no configurada.');
            return "Hola, en este momento no podemos procesar tu solicitud automáticamente. Por favor intenta más tarde.";
        }

        // 1. Mensajes iniciales y contexto del sistema
        $messages = [
            [
                'role' => 'system',
                'content' => "Eres un asistente virtual de atención médica para el consultorio. Tu objetivo es ser amable, profesional y ayudar a los pacientes a consultar disponibilidad de citas y resolver sus dudas.\n" .
                             "Hoy es " . Carbon::now()->format('Y-m-d (l)') . ".\n" .
                             "Si el paciente consulta sobre disponibilidad de citas o turnos, DEBES invocar la herramienta 'consultar_disponibilidad' indicando la fecha solicitada en formato YYYY-MM-DD. Si no menciona fecha explícita, usa la fecha de hoy."
            ],
            [
                'role' => 'user',
                'content' => $mensajeCliente
            ]
        ];

        // Definicón de Herramientas (Function Calling)
        $tools = [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'consultar_disponibilidad',
                    'description' => 'Consulta en la base de datos de la clínica los cupos disponibles para una fecha específica.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'fecha' => [
                                'type' => 'string',
                                'description' => 'Fecha para consultar disponibilidad en formato YYYY-MM-DD',
                            ],
                        ],
                        'required' => ['fecha'],
                    ],
                ],
            ]
        ];

        // 2. Primera llamada a la IA
        $response = Http::withToken($this->apiKey)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4o-mini',
                'messages' => $messages,
                'tools' => $tools,
                'tool_choice' => 'auto',
            ]);

        if ($response->failed()) {
            Log::error('Error consumiendo OpenAI API: ' . $response->body());
            return "Disculpa, estoy experimentando inconvenientes técnicos en este momento. Por favor reintenta luego.";
        }

        $responseData = $response->json();
        $choice = $responseData['choices'][0]['message'] ?? null;

        // 3. Evaluar si la IA solicita consultar la base de datos
        if (isset($choice['tool_calls']) && count($choice['tool_calls']) > 0) {
            $toolCall = $choice['tool_calls'][0];
            $functionName = $toolCall['function']['name'];
            $arguments = json_decode($toolCall['function']['arguments'], true);

            if ($functionName === 'consultar_disponibilidad') {
                $fecha = $arguments['fecha'] ?? Carbon::now()->format('Y-m-d');
                
                // Ejecutar consulta real en la base de datos
                $disponibilidadInfo = $this->obtenerDisponibilidadDB($fecha);

                // Inyectar el resultado de la base de datos en el historial de la conversación
                $messages[] = $choice;
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => $toolCall['id'],
                    'content' => json_encode($disponibilidadInfo),
                ];

                // 4. Segunda llamada a la IA para redactar la respuesta natural al cliente
                $secondResponse = Http::withToken($this->apiKey)
                    ->post('https://api.openai.com/v1/chat/completions', [
                        'model' => 'gpt-4o-mini',
                        'messages' => $messages,
                    ]);

                if ($secondResponse->successful()) {
                    return $secondResponse->json()['choices'][0]['message']['content'] ?? "No se pudo generar una respuesta.";
                }
            }
        }

        return $choice['content'] ?? "Hola, ¿en qué puedo ayudarte hoy?";
    }

    /**
     * Consulta en la base de datos la disponibilidad real de citas/turnos en la cola.
     */
    protected function obtenerDisponibilidadDB(string $fecha): array
    {
        try {
            // Ejemplo de consulta en la base de datos de AgendaMedica (Ajusta la tabla/columnas según tu esquema)
            $citasOcupadas = DB::table('colas')
                ->whereDate('fecha', $fecha)
                ->whereIn('estatus', ['pendiente', 'confirmada', 'atendido'])
                ->count();

            $limiteDiario = 15; // Límite configurado de pacientes
            $disponibles = max(0, $limiteDiario - $citasOcupadas);

            return [
                'fecha' => $fecha,
                'cupos_totales' => $limiteDiario,
                'cupos_ocupados' => $citasOcupadas,
                'cupos_disponibles' => $disponibles,
                'hay_disponibilidad' => $disponibles > 0,
            ];
        } catch (\Exception $e) {
            Log::error("Error consultando disponibilidad DB para la fecha {$fecha}: " . $e->getMessage());
            return [
                'fecha' => $fecha,
                'error' => 'No se pudo verificar la base de datos en este momento.',
                'hay_disponibilidad' => false
            ];
        }
    }
}