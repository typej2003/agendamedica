<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController extends Controller
{
    /**
     * Validación inicial del Webhook requerida por Meta.
     */
    public function verify(Request $request)
    {
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        $verifyToken = config('services.whatsapp.verify_token');

        if ($mode === 'subscribe' && $token === $verifyToken) {
            return response($challenge, 200);
        }

        return response()->json(['error' => 'Token de verificación no válido'], 403);
    }

    /**
     * Recepción de mensajes e interacciones de usuarios en tiempo real.
     */
    public function handle(Request $request): JsonResponse
    {
        $body = $request->all();

        Log::info('Webhook recibido de WhatsApp:', $body);

        if (isset($body['entry'][0]['changes'][0]['value']['messages'][0])) {
            $messageData = $body['entry'][0]['changes'][0]['value']['messages'][0];
            $senderPhone = $messageData['from']; // Número del paciente
            $messageType = $messageData['type'];

            if ($messageType === 'text') {
                $textBody = $messageData['text']['body'];

                // AQUÍ: Procesar el mensaje con tu lógica de IA o responder al paciente
                Log::info("Mensaje recibido de {$senderPhone}: {$textBody}");
            }
        }

        return response()->json(['status' => 'EVENT_RECEIVED'], 200);
    }
}