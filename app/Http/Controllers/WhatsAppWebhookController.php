<?php

namespace App\Http\Controllers;

use App\Services\GeminiService;
use App\Services\WhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Webhook de WhatsApp (Meta): el transporte del chatbot.
 *
 * Lo que este controlador **sí** garantiza (auditoría del chatbot de WhatsApp, 2026-10-17, §6):
 *
 * - la firma `X-Hub-Signature-256` la valida el middleware `whatsapp.firma` (ruta `routes/api.php`);
 * - el `GET` de verificación **no** acepta un token vacío contra un `verify_token` vacío;
 * - un `messageId` se procesa **una sola vez** (Meta reintenta cuando no recibe 200, y antes cada
 *   reintento creaba otra cita);
 * - si algo falla se responde **500**, para que Meta reintente lo que corresponde, en vez del 200
 *   silencioso de antes —con la salvedad de los `statuses`, que son sólo un registro y no se reintentan.
 *
 * La conversación y las reglas de agenda **no** viven acá: eso es `GeminiService` (atajos + Gemini) y
 * `PacienteAtencionContextService` (lectura y reserva).
 */
class WhatsAppWebhookController extends Controller
{
    /** Cuánto se recuerda un `messageId` ya procesado. Meta reintenta por horas, no por días. */
    private const VIGENCIA_DEL_MENSAJE = 3600;

    protected WhatsAppService $whatsAppService;
    protected GeminiService $geminiService;

    public function __construct(WhatsAppService $whatsAppService, GeminiService $geminiService)
    {
        $this->whatsAppService = $whatsAppService;
        $this->geminiService = $geminiService;
    }

    /**
     * Verificación del Webhook de WhatsApp (GET).
     *
     * Meta manda `hub.mode`, `hub.verify_token` y `hub.challenge`; PHP convierte los puntos de los
     * parámetros en guiones bajos, así que llegan como `hub_mode` y compañía.
     */
    public function verify(Request $request)
    {
        $verifyToken = trim((string) config('services.whatsapp.verify_token'));

        if ($verifyToken === '') {
            Log::error('Webhook de WhatsApp sin WHATSAPP_VERIFY_TOKEN: no se puede verificar el número.');

            return response('Webhook no configurado', 503);
        }

        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        if ($mode === 'subscribe' && is_string($token) && hash_equals($verifyToken, $token)) {
            return response($challenge, 200);
        }

        return response('Token de verificación inválido', 403);
    }

    /**
     * Recepción y procesamiento de eventos/mensajes (POST).
     */
    public function handle(Request $request): JsonResponse
    {
        $data = $request->all();

        Log::info('Webhook recibido de WhatsApp: ' . json_encode($data));

        $fallo = false;

        foreach (($data['entry'] ?? []) as $entry) {
            foreach (($entry['changes'] ?? []) as $change) {
                $valor = $change['value'] ?? null;

                if (! is_array($valor)) {
                    continue;
                }

                $this->registrarEstados($valor);

                if (! empty($valor['messages']) && is_array($valor['messages'])) {
                    $phoneNumberId = $valor['metadata']['phone_number_id'] ?? null;

                    foreach ($valor['messages'] as $mensaje) {
                        if (! is_array($mensaje)) {
                            continue;
                        }

                        if (! $this->procesarMensaje($mensaje, $phoneNumberId)) {
                            $fallo = true;
                        }
                    }
                }
            }
        }

        if ($fallo) {
            // Meta reintenta lo que no contestó 200. La deduplicación por `messageId` evita que el
            // reintento de un mensaje que sí se contestó vuelva a crear la cita.
            return response()->json(['status' => 'ERROR'], 500);
        }

        return response()->json(['status' => 'EVENT_RECEIVED'], 200);
    }

    /**
     * Los eventos de estado (`sent`, `delivered`, `read`, `failed`) sólo se registran: no hay nada que
     * reintentar, así que nunca hacen fallar la respuesta.
     */
    private function registrarEstados(array $valor): void
    {
        if (empty($valor['statuses']) || ! is_array($valor['statuses'])) {
            return;
        }

        foreach ($valor['statuses'] as $estado) {
            if (! is_array($estado)) {
                continue;
            }

            $destinatario = $estado['recipient_id'] ?? null;
            $status = $estado['status'] ?? null;
            $id = $estado['id'] ?? null;

            if ($status === 'failed') {
                $errores = $estado['errors'] ?? [];
                Log::error("WhatsApp status FAILED para {$destinatario} (Msg ID: {$id}): " . json_encode($errores));
            } else {
                Log::info("WhatsApp status [{$status}] para {$destinatario} (Msg ID: {$id})");
            }
        }
    }

    /**
     * Procesa **un** mensaje del lote. Devuelve `false` si algo falló y hay que pedirle a Meta que
     * reintente; `true` si se atendió o si ya se había atendido (duplicado).
     */
    private function procesarMensaje(array $mensaje, ?string $phoneNumberId): bool
    {
        $from = $mensaje['from'] ?? null;

        if (! is_string($from) || $from === '') {
            Log::warning('Mensaje de WhatsApp sin remitente: se ignora.', ['mensaje' => $mensaje]);

            return true;
        }

        $messageId = $mensaje['id'] ?? null;
        $tipo = $mensaje['type'] ?? null;
        $clave = is_string($messageId) && $messageId !== '' ? "whatsapp:mensaje:{$messageId}" : null;

        // Deduplicación: Meta reintenta el mismo `messageId` cuando el webhook no contesta 200. Sin esto,
        // cada reintento volvía a responderle a la paciente y a crear otra cita.
        if ($clave !== null && ! Cache::add($clave, true, self::VIGENCIA_DEL_MENSAJE)) {
            Log::info("Mensaje de WhatsApp ya procesado ({$messageId}): se ignora el reintento.");

            return true;
        }

        $texto = $this->textoDelMensaje($mensaje, $tipo);

        if ($texto === '') {
            // Multimedia y ubicaciones: se avisa una vez y no se reintenta.
            if (in_array($tipo, ['audio', 'voice', 'image', 'document', 'video', 'location', 'sticker'], true)) {
                Log::info("Mensaje no textual de {$from} (Tipo: {$tipo}): notificando al usuario.");

                if (! $this->enviar($from, 'Hola 👋 Por el momento solo podemos procesar mensajes de texto. Por favor escríbenos tu respuesta en texto para atenderte con gusto. 😊', $phoneNumberId)) {
                    $this->liberar($clave);

                    return false;
                }
            }

            return true;
        }

        try {
            Log::info("Mensaje procesable de {$from} (Tipo: {$tipo}, ID: {$messageId}): {$texto}");

            $respuesta = $this->geminiService->generarRespuesta($texto, $from);

            Log::info("Respuesta generada para {$from}: {$respuesta}");

            if ($respuesta === '' || ! $this->enviar($from, $respuesta, $phoneNumberId)) {
                $this->liberar($clave);

                return false;
            }
        } catch (\Throwable $e) {
            Log::error('Error procesando el mensaje de WhatsApp: ' . $e->getMessage(), [
                'from' => $from,
                'message_id' => $messageId,
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            $this->liberar($clave);

            return false;
        }

        return true;
    }

    /**
     * El texto del mensaje: el del `text` o, en un interactivo, el título del botón/ítem elegido. Vacío
     * para todo lo demás (audio, imagen, documento…).
     */
    private function textoDelMensaje(array $mensaje, ?string $tipo): string
    {
        if ($tipo === 'text') {
            return trim((string) ($mensaje['text']['body'] ?? ''));
        }

        if ($tipo === 'interactive') {
            $interactivo = $mensaje['interactive'] ?? [];
            $clase = $interactivo['type'] ?? '';

            if ($clase === 'button_reply') {
                return trim((string) ($interactivo['button_reply']['title'] ?? $interactivo['button_reply']['id'] ?? ''));
            }

            if ($clase === 'list_reply') {
                return trim((string) ($interactivo['list_reply']['title'] ?? $interactivo['list_reply']['id'] ?? ''));
            }
        }

        return '';
    }

    /** Un envío fallido es un fallo del webhook: Meta reintenta y la paciente recibe su respuesta. */
    private function enviar(string $from, string $texto, ?string $phoneNumberId): bool
    {
        $enviado = $this->whatsAppService->sendMessage($from, $texto, $phoneNumberId);

        if (! $enviado) {
            Log::error("WhatsApp no pudo enviar la respuesta a {$from}: se pide el reintento a Meta.");
        }

        return $enviado;
    }

    /**
     * Suelta la marca de "ya procesado" cuando el envío falló, para que el reintento de Meta sí lo
     * procese. Si el mensaje ya se procesó y sólo falló un efecto secundario, el reintento duplicaría la
     * respuesta —se prefiere eso a una paciente sin contestación—.
     */
    private function liberar(?string $clave): void
    {
        if ($clave !== null) {
            Cache::forget($clave);
        }
    }
}
