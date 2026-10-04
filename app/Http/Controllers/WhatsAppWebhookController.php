<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use App\Services\WhatsAppService;

class WhatsAppWebhookController extends Controller
{
    protected WhatsAppService $whatsAppService;

    public function __construct(WhatsAppService $whatsAppService)
    {
        $this->whatsAppService = $whatsAppService;
    }

    /**
     * Verificación del Webhook de WhatsApp (GET)
     */
    public function verify(Request $request)
    {
        $verifyToken = config('services.whatsapp.verify_token', env('WHATSAPP_VERIFY_TOKEN'));

        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        if ($mode === 'subscribe' && $token === $verifyToken) {
            return response($challenge, 200);
        }

        return response('Token de verificación inválido', 403);
    }

    /**
     * Recepción y procesamiento de eventos/mensajes (POST)
     */
    public function handle(Request $request): JsonResponse
    {
        $data = $request->all();

        Log::info('Webhook recibido de WhatsApp: ' . json_encode($data));

        try {
            if (isset($data['entry'][0]['changes'][0]['value']['messages'][0])) {
                $messageData = $data['entry'][0]['changes'][0]['value']['messages'][0];

                $from = $messageData['from'] ?? null;
                $messageId = $messageData['id'] ?? null;
                $timestamp = $messageData['timestamp'] ?? null;
                $messageType = $messageData['type'] ?? null;

                if ($messageType === 'text') {
                    $bodyText = $messageData['text']['body'] ?? '';

                    Log::info("Mensaje recibido de {$from} (ID: {$messageId}, Time: {$timestamp}): {$bodyText}");

                    // Procesamiento con OpenAI API
                    $aiReply = $this->getOpenAIResponse($bodyText);

                    if ($aiReply) {
                        $this->whatsAppService->sendMessage($from, $aiReply);
                    } else {
                        $this->whatsAppService->sendMessage($from, 'Lo siento, no pude procesar tu solicitud en este momento.');
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::error('Excepción general en WhatsAppWebhookController: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        // Siempre responder 200 a WhatsApp para evitar bucles de reintento
        return response()->json(['status' => 'EVENT_RECEIVED'], 200);
    }

    /**
     * Consulta a la API de OpenAI
     */
    protected function getOpenAIResponse(string $prompt): ?string
    {
        $apiKey = config('services.openai.api_key', env('OPENAI_API_KEY'));

        if (empty($apiKey)) {
            Log::error('API Key de OpenAI no configurada.');
            return null;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(30)->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4o-mini',
                'messages' => [
                    ['role' => 'system', 'content' => 'Eres un asistente virtual atento y conciso.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.7,
            ]);

            if ($response->successful()) {
                $responseData = $response->json();
                return $responseData['choices'][0]['message']['content'] ?? null;
            }

            Log::error('Error consumiendo OpenAI API: ' . $response->body());
            return null;
        } catch (\Exception $e) {
            Log::error('Excepción al conectar con OpenAI: ' . $e->getMessage());
            return null;
        }
    }

    public function handleWebhook(Request $request, GeminiService $gemini, WhatsAppService $whatsApp)
    {
        $body = $request->all();
        $entry = $body['entry'][0]['changes'][0]['value'] ?? null;

        if (!empty($entry['messages'][0])) {
            $message = $entry['messages'][0];
            $from = $message['from']; // Teléfono del paciente
            $text = $message['text']['body'] ?? '';

            if (!empty($text)) {
                // Procesar con Gemini y obtener la respuesta
                $respuesta = $gemini->procesarMensaje($text, $from);

                // Responder al paciente por WhatsApp
                $whatsApp->sendTextMessage($from, $respuesta);
            }
        }

        return response()->json(['status' => 'EVENT_RECEIVED'], 200);
    }
}