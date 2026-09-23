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

        $verifyToken = config('services.whatsapp.verify_token') ?: env('WHATSAPP_VERIFY_TOKEN');

        if ($mode === 'subscribe' && $token === $verifyToken) {
            return response($challenge, 200)->header('Content-Type', 'text/plain');
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

            $id              = $messageData['id'] ?? null;
            $telefonoCliente = $messageData['from'] ?? null;
            $timestamp       = $messageData['timestamp'] ?? null;
            $messageType     = $messageData['type'] ?? null;

            if ($messageType === 'text') {
                $mensaje = $messageData['text']['body'] ?? null;

                if ($mensaje !== null) {
                    // Usamos 'append' en lugar de 'put' para mantener un historial.
                    // También agregamos fecha y número para saber quién escribe.
                    $lineaTexto = "[" . date('Y-m-d H:i:s') . "] De {$telefonoCliente}: {$mensaje}";
                    Storage::disk('local')->append('text.txt', $lineaTexto);

                    Log::info("Mensaje recibido de {$telefonoCliente} (ID: {$id}, Time: {$timestamp}): {$mensaje}");
                }
            }
        }

        return response()->json(['status' => 'EVENT_RECEIVED'], 200);
    }
}