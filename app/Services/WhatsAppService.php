<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    protected string $token;
    protected string $phoneNumberId;

    public function __construct()
    {
        $this->token = config('services.whatsapp.token');
        $this->phoneNumberId = config('services.whatsapp.phone_number_id');
    }

    /**
     * Envía una plantilla preaprobada por WhatsApp.
     *
     * @param string $recipientPhone Número en formato internacional sin el signo + (ej: 584241234567)
     * @param string $templateName Nombre exacto de la plantilla en Meta (ej: notificacion_paciente)
     * @param array $parameters Parámetros variables para la plantilla {{1}}, {{2}}, etc.
     * @param string $languageCode Código de idioma (por defecto 'es')
     * @return array
     */
    public function sendTemplate(string $recipientPhone, string $templateName, array $parameters = [], string $languageCode = 'es'): array
    {
        $url = "https://graph.facebook.com/v19.0/{$this->phoneNumberId}/messages";

        $formattedParams = array_map(function ($value) {
            return [
                'type' => 'text',
                'text' => (string) $value,
            ];
        }, $parameters);

        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $recipientPhone,
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

        $response = Http::withToken($this->token)
            ->post($url, $payload);

        if ($response->failed()) {
            Log::error('Error al enviar plantilla de WhatsApp', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
        }

        return $response->json();
    }

    /**
     * Envía un mensaje de texto libre (Solo permitido dentro de la ventana de 24 horas tras respuesta del paciente).
     */
    public function sendTextMessage(string $recipientPhone, string $messageText): array
    {
        $url = "https://graph.facebook.com/v19.0/{$this->phoneNumberId}/messages";

        $response = Http::withToken($this->token)
            ->post($url, [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $recipientPhone,
                'type' => 'text',
                'text' => [
                    'preview_url' => false,
                    'body' => $messageText,
                ],
            ]);

        if ($response->failed()) {
            Log::error('Error al enviar mensaje libre de WhatsApp', [
                'status' => $response->status(),
                'body' => $response->json(),
            ]);
        }

        return $response->json();
    }
}