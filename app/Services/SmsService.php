<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SMS por **Twilio** desde el servidor (WEB-2.6).
 *
 * Por qué el servidor y no el teléfono: la web es online y **no** puede depender del gateway que el
 * escritorio tenía en la PC del consultorio (`APISMS\A13API.EXE`); y el SMS nativo de Android solo
 * existe en el móvil. La credencial es **del servidor** (`config/services.php` → `twilio`): los
 * médicos no configuran proveedores —decisión de Alexander, 2026-10-09— y el consumo se mide por
 * médico con `notificaciones_cita`.
 *
 * El contrato es el mismo que `WhatsAppService`: devuelve la respuesta cruda del proveedor en vez de
 * esconderla, porque es lo único que permite reclamar un mensaje no entregado y depurar un rechazo.
 */
class SmsService
{
    private string $sid;
    private string $token;
    private string $from;

    public function __construct()
    {
        $this->sid = (string) config('services.twilio.sid', '');
        $this->token = (string) config('services.twilio.token', '');
        $this->from = (string) config('services.twilio.from', '');
    }

    public function configurado(): bool
    {
        return $this->sid !== '' && $this->token !== '' && $this->from !== '';
    }

    /**
     * @param  string  $destino  Dígitos internacionales sin `+` (lo que devuelve `Telefono::internacional`).
     * @return array{ok: bool, respuesta: array}
     */
    public function enviar(string $destino, string $mensaje): array
    {
        if (! $this->configurado()) {
            return ['ok' => false, 'respuesta' => ['error' => 'Twilio no está configurado en el servidor.']];
        }

        $url = "https://api.twilio.com/2010-04-01/Accounts/{$this->sid}/Messages.json";

        try {
            $respuesta = Http::asForm()
                ->withBasicAuth($this->sid, $this->token)
                ->post($url, [
                    // Twilio quiere E.164: el `+` y el número, sin separadores.
                    'To'   => '+' . ltrim($destino, '+'),
                    'From' => $this->from,
                    'Body' => $mensaje,
                ]);

            if ($respuesta->successful()) {
                Log::info("SMS enviado a {$destino}: " . json_encode($respuesta->json()));

                return ['ok' => true, 'respuesta' => $respuesta->json() ?? []];
            }

            Log::error("Error de Twilio enviando a {$destino}: (HTTP {$respuesta->status()}) " . $respuesta->body());

            return [
                'ok' => false,
                'respuesta' => $respuesta->json() ?? ['http_status' => $respuesta->status(), 'body' => $respuesta->body()],
            ];
        } catch (\Throwable $e) {
            Log::error("Excepción enviando SMS a {$destino}: " . $e->getMessage());

            return ['ok' => false, 'respuesta' => ['error' => $e->getMessage()]];
        }
    }
}
