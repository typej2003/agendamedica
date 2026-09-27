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
}