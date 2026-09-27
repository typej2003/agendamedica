<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Services\WhatsAppService;
use App\Services\WhatsAppAIService;

class WhatsAppWebhookController extends Controller
{
    protected WhatsAppService $whatsAppService;
    protected WhatsAppAIService $aiService;

    public function __construct(WhatsAppService $whatsAppService, WhatsAppAIService $aiService)
    {
        $this->whatsAppService = $whatsAppService;
        $this->aiService = $aiService;
    }

    /**
     * Validación inicial del Webhook requerida por Meta.
     */
    public function verify(Request $request)
    {
        // PHP convierte los puntos en los parámetros GET a guiones bajos.
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

                if ($mensaje !== null && $telefonoCliente !== null) {
                    // 1. Guardar registro del mensaje entrante
                    $lineaEntrante = "[" . date('Y-m-d H:i:s') . "] De {$telefonoCliente}: {$mensaje}" . PHP_EOL;
                    Storage::disk('local')->append('text.txt', $lineaEntrante);

                    Log::info("Mensaje recibido de {$telefonoCliente} (ID: {$id}, Time: {$timestamp}): {$mensaje}");

                    // 2. Procesar respuesta con Inteligencia Artificial y consulta a DB
                    $respuestaIA = $this->aiService->responderMensaje($telefonoCliente, $mensaje);

                    // 3. Responder al cliente por WhatsApp
                    $this->whatsAppService->sendMessage($telefonoCliente, $respuestaIA);

                    // 4. Guardar registro de la respuesta enviada por la IA
                    $lineaSalida = "[" . date('Y-m-d H:i:s') . "] Para {$telefonoCliente} (IA): {$respuestaIA}" . PHP_EOL;
                    Storage::disk('local')->append('text.txt', $lineaSalida);
                }
            }
        }

        // Meta siempre espera un código 200 para no reintentar el envío
        return response()->json(['status' => 'EVENT_RECEIVED'], 200);
    }
}