<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    protected string $token;
    protected string $phoneNumberId;
    protected string $apiVersion;

    public function __construct()
    {
        $this->token = config('services.whatsapp.token', env('WHATSAPP_TOKEN', ''));
        $this->phoneNumberId = config('services.whatsapp.phone_number_id', env('WHATSAPP_PHONE_NUMBER_ID', ''));
        $this->apiVersion = config('services.whatsapp.version', env('WHATSAPP_API_VERSION', 'v19.0'));
    }

    /**
     * Envía un mensaje de texto simple a través de la API Graph de WhatsApp (Meta).
     *
     * @param string $to Número de teléfono del destinatario (ej. 584165800403)
     * @param string $message Texto a enviar
     * @param string|null $fromPhoneNumberId ID del número emisor en Meta (si es nulo, usa el configurado en .env)
     * @return bool
     */
    public function sendMessage(string $to, string $message, ?string $fromPhoneNumberId = null): bool
    {
        $phoneId = $fromPhoneNumberId ?: $this->phoneNumberId;
        $cleanTo = preg_replace('/[^0-9]/', '', $to);

        if (empty($phoneId)) {
            Log::error('WhatsAppService::sendMessage cancelado: no hay phone_number_id configurado ni recibido en webhook.');
            return false;
        }

        $url = "https://graph.facebook.com/{$this->apiVersion}/{$phoneId}/messages";

        try {
            $response = Http::withToken($this->token)
                ->post($url, [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $cleanTo,
                    'type' => 'text',
                    'text' => [
                        'preview_url' => false,
                        'body' => $message,
                    ],
                ]);

            if ($response->successful()) {
                Log::info("Mensaje enviado con éxito a {$cleanTo} desde Phone ID {$phoneId}: " . json_encode($response->json()));
                return true;
            }

            Log::error("Error enviando mensaje de WhatsApp a {$cleanTo} desde Phone ID {$phoneId}: (HTTP {$response->status()}) " . $response->body());
            return false;
        } catch (\Exception $e) {
            Log::error("Excepción en WhatsAppService::sendMessage: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Envía un mensaje de texto libre por WhatsApp devolviendo array de respuesta.
     *
     * @param string $to Número de teléfono del destinatario con código de país
     * @param string $message Texto del mensaje
     * @param string|null $fromPhoneNumberId ID del número emisor en Meta (opcional)
     * @return array
     */
    public function sendTextMessage(string $to, string $message, ?string $fromPhoneNumberId = null): array
    {
        $phoneId = $fromPhoneNumberId ?: $this->phoneNumberId;
        $cleanTo = preg_replace('/[^0-9]/', '', $to);

        if (empty($phoneId)) {
            Log::error('WhatsAppService::sendTextMessage cancelado: no hay phone_number_id configurado.');
            return [
                'success' => false,
                'error' => 'No hay phone_number_id configurado',
            ];
        }

        $url = "https://graph.facebook.com/{$this->apiVersion}/{$phoneId}/messages";

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $cleanTo,
            'type' => 'text',
            'text' => [
                'preview_url' => false,
                'body' => $message,
            ],
        ];

        try {
            $response = Http::withToken($this->token)->post($url, $payload);

            if ($response->successful()) {
                Log::info("sendTextMessage enviado con éxito a {$cleanTo} desde Phone ID {$phoneId}: " . json_encode($response->json()));
            } else {
                Log::error("sendTextMessage error enviando a {$cleanTo} desde Phone ID {$phoneId}: (HTTP {$response->status()}) " . $response->body());
            }

            return [
                'success' => $response->successful(),
                'status' => $response->status(),
                'data' => $response->json(),
            ];
        } catch (\Throwable $e) {
            Log::error('WhatsAppService::sendTextMessage excepción: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Alias en español para sendTextMessage
     */
    public function enviarMensajeTexto(string $to, string $message, ?string $fromPhoneNumberId = null): array
    {
        return $this->sendTextMessage($to, $message, $fromPhoneNumberId);
    }

    /**
     * Envía una plantilla preaprobada por WhatsApp.
     *
     * @param string $recipientPhone Número en formato internacional sin el signo + (ej: 584241234567)
     * @param string $templateName Nombre exacto de la plantilla en Meta (ej: notificacion_paciente)
     * @param array $parameters Parámetros variables para la plantilla {{1}}, {{2}}, etc.
     * @param string $languageCode Código de idioma (por defecto 'es')
     * @param string|null $fromPhoneNumberId ID del número emisor en Meta (opcional)
     * @return array
     */
    public function sendTemplate(
        string $recipientPhone,
        string $templateName,
        array $parameters = [],
        string $languageCode = 'es',
        ?string $fromPhoneNumberId = null
    ): array {
        $phoneId = $fromPhoneNumberId ?: $this->phoneNumberId;
        $cleanPhone = preg_replace('/[^0-9]/', '', $recipientPhone);

        if (empty($phoneId)) {
            Log::error('WhatsAppService::sendTemplate cancelado: no hay phone_number_id configurado.');
            return [
                'error' => 'No hay phone_number_id configurado',
            ];
        }

        $url = "https://graph.facebook.com/{$this->apiVersion}/{$phoneId}/messages";

        $formattedParams = array_map(function ($value) {
            return [
                'type' => 'text',
                'text' => (string) $value,
            ];
        }, $parameters);

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $cleanPhone,
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => [
                    'code' => $languageCode,
                ],
            ],
        ];

        if (!empty($formattedParams)) {
            $payload['template']['components'] = [
                [
                    'type' => 'body',
                    'parameters' => $formattedParams,
                ],
            ];
        }

        try {
            $response = Http::withToken($this->token)->post($url, $payload);

            if ($response->successful()) {
                Log::info("Plantilla {$templateName} enviada con éxito a {$cleanPhone} desde Phone ID {$phoneId}: " . json_encode($response->json()));
            } else {
                Log::error("Error enviando plantilla WhatsApp {$templateName} a {$cleanPhone} desde Phone ID {$phoneId}: (HTTP {$response->status()}) " . $response->body());
            }

            return $response->json() ?? [];
        } catch (\Throwable $e) {
            Log::error("Excepción en WhatsAppService::sendTemplate: " . $e->getMessage());
            return [
                'error' => $e->getMessage(),
            ];
        }
    }
}