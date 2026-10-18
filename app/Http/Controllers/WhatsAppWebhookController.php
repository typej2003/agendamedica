<?php

namespace App\Http\Controllers;

use App\Services\GeminiService;
use App\Services\WhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController extends Controller
{
    protected WhatsAppService $whatsAppService;
    protected GeminiService $geminiService;

    public function __construct(WhatsAppService $whatsAppService, GeminiService $geminiService)
    {
        $this->whatsAppService = $whatsAppService;
        $this->geminiService = $geminiService;
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
            $entry = $data['entry'][0] ?? null;
            $changes = $entry['changes'][0] ?? null;
            $value = $changes['value'] ?? null;

            if ($value) {
                // 1. Manejo y registro de eventos de estado (sent, delivered, read, failed)
                if (!empty($value['statuses']) && is_array($value['statuses'])) {
                    foreach ($value['statuses'] as $statusData) {
                        $recipientId = $statusData['recipient_id'] ?? null;
                        $status = $statusData['status'] ?? null;
                        $statusId = $statusData['id'] ?? null;

                        if ($status === 'failed') {
                            $errors = $statusData['errors'] ?? [];
                            Log::error("WhatsApp status FAILED para {$recipientId} (Msg ID: {$statusId}): " . json_encode($errors));
                        } else {
                            Log::info("WhatsApp status [{$status}] para {$recipientId} (Msg ID: {$statusId})");
                        }
                    }
                }

                // 2. Manejo de mensajes entrantes
                if (!empty($value['messages']) && is_array($value['messages'])) {
                    $metadata = $value['metadata'] ?? [];
                    $incomingPhoneNumberId = $metadata['phone_number_id'] ?? null;

                    foreach ($value['messages'] as $messageData) {
                        $from = $messageData['from'] ?? null;
                        $messageId = $messageData['id'] ?? null;
                        $timestamp = $messageData['timestamp'] ?? null;
                        $messageType = $messageData['type'] ?? null;

                        $bodyText = '';
                        if ($messageType === 'text') {
                            $bodyText = trim($messageData['text']['body'] ?? '');
                        } elseif ($messageType === 'interactive') {
                            $interactive = $messageData['interactive'] ?? [];
                            $type = $interactive['type'] ?? '';
                            if ($type === 'button_reply') {
                                $bodyText = trim($interactive['button_reply']['title'] ?? $interactive['button_reply']['id'] ?? '');
                            } elseif ($type === 'list_reply') {
                                $bodyText = trim($interactive['list_reply']['title'] ?? $interactive['list_reply']['id'] ?? '');
                            }
                        }

                        if (!empty($bodyText)) {
                            Log::info("Mensaje procesable de {$from} (Tipo: {$messageType}, ID: {$messageId}): {$bodyText}");

                            // Procesar con Gemini AI inyectando el número para contexto del paciente
                            $aiReply = $this->geminiService->generarRespuesta($bodyText, $from);

                            Log::info("Respuesta generada por Gemini para {$from}: {$aiReply}");

                            // Enviar la respuesta vía WhatsApp asegurando el phone_number_id del destinatario original
                            $this->whatsAppService->sendMessage($from, $aiReply, $incomingPhoneNumberId);
                        } elseif (in_array($messageType, ['audio', 'voice', 'image', 'document', 'video', 'location'])) {
                            Log::info("Mensaje no textual de {$from} (Tipo: {$messageType}): notificando al usuario.");
                            $this->whatsAppService->sendMessage(
                                $from,
                                "Hola 👋 Por el momento solo podemos procesar mensajes de texto. Por favor escríbenos tu respuesta en texto para atenderte con gusto. 😊",
                                $incomingPhoneNumberId
                            );
                        }
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

        // Siempre responder 200 a WhatsApp para evitar bucles de reintento de Meta
        return response()->json(['status' => 'EVENT_RECEIVED'], 200);
    }
}