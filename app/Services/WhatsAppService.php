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
        $this->apiVersion = config('services.whatsapp.version', env('WHATSAPP_API_VERSION', 'v18.0'));
    }

    /**
     * Envía un mensaje de texto simple a través de la API Graph de WhatsApp (Meta).
     *
     * @param string $to Número de teléfono del destinatario (ej. 584165800403)
     * @param string $message Texto a enviar
     * @return bool
     */
    public function sendMessage(string $to, string $message): bool
    {
        $url = "https://graph.facebook.com/{$this->apiVersion}/{$this->phoneNumberId}/messages";

        try {
            $response = Http::withToken($this->token)
                ->post($url, [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $to,
                    'type' => 'text',
                    'text' => [
                        'preview_url' => false,
                        'body' => $message,
                    ],
                ]);

            if ($response->successful()) {
                Log::info("Mensaje enviado con éxito a {$to}");
                return true;
            }

            Log::error("Error enviando mensaje de WhatsApp a {$to}: " . $response->body());
            return false;
        } catch (\Exception $e) {
            Log::error("Excepción en WhatsAppService::sendMessage: " . $e->getMessage());
            return false;
        }
    }

        /**
     * Envía un mensaje de texto libre por WhatsApp.
     *
     * @param string $to Número de teléfono del destinatario con código de país
     * @param string $message Texto del mensaje
     * @return array
     */
    public function sendTextMessage(string $to, string $message): array
    {
        $url = "https://graph.facebook.com/v19.0/{$this->phoneNumberId}/messages";

        // Limpiar número (solo dígitos)
        $cleanTo = preg_replace('/[^0-9]/', '', $to);

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $cleanTo,
            'type' => 'text',
            'text' => [
                'preview_url' => false,
                'body' => $message
            ]
        ];

        try {
            $response = Http::withToken($this->token)->post($url, $payload);
            return [
                'success' => $response->successful(),
                'status' => $response->status(),
                'data' => $response->json()
            ];
        } catch (\Throwable $e) {
            Log::error('WhatsAppService sendTextMessage error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}