<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class WhatsAppWebhookController extends Controller
{
    /**
     * Validación inicial del Webhook requerida por Meta.
     */
        public function verify(Request $request)
    {
        $mode = $request->query('hub_mode', $request->query('hub.mode'));
        $token = $request->query('hub_verify_token', $request->query('hub.verify_token'));
        $challenge = $request->query('hub_challenge', $request->query('hub.challenge'));

        $verifyToken = config('services.whatsapp.verify_token') ?: env('WHATSAPP_VERIFY_TOKEN', 'HolaNovato');

        // Retorna todos los valores para depurar exactamente qué está fallando
        return response()->json([
            'token_recibido_en_url' => $token,
            'token_esperado_laravel' => $verifyToken,
            'mode_recibido'          => $mode,
            'challenge_recibido'     => $challenge,
            'todos_los_parametros'   => $request->all(),
        ]);
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

            $id              = $messageData['id'] ?? null;
            $telefonoCliente = $messageData['from'] ?? null;
            $timestamp       = $messageData['timestamp'] ?? null;
            $messageType     = $messageData['type'] ?? null;

            if ($messageType === 'text') {
                $mensaje = $messageData['text']['body'] ?? null;

                if ($mensaje !== null) {
                    // Guarda en storage/app/text.txt
                    Storage::disk('local')->put('text.txt', $mensaje);

                    Log::info("Mensaje recibido de {$telefonoCliente} (ID: {$id}, Time: {$timestamp}): {$mensaje}");
                }
            }
        }

        return response()->json(['status' => 'EVENT_RECEIVED'], 200);
    }
}