<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * **El SMS del servidor** (WEB-2.6), con el proveedor detrás de una configuración (O-55, 2026-11-02).
 *
 * Por qué el servidor y no el teléfono: la web es online y **no** puede depender del gateway que el
 * escritorio tenía en la PC del consultorio (`APISMS\A13API.EXE`); y el SMS nativo de Android solo
 * existe en el móvil. La credencial es **del servidor** (`config/services.php`): los médicos no
 * configuran proveedores —decisión de Alexander, 2026-10-09— y el consumo se mide por médico con
 * `notificaciones_cita`.
 *
 * ## Los proveedores (`SMS_PROVEEDOR`)
 *
 *  - **`twilio`** — el de siempre, y el que se usa si la variable no está: HTTP a `Messages.json` con la
 *    credencial de `services.twilio`. Es el único que manda un SMS de verdad.
 *  - **`log`** — **sólo para desarrollo**: escribe el mensaje en el log y devuelve `ok`. Sirve para probar
 *    el circuito completo (código de reserva, saludo, recordatorio) sin gastar ni dar de alta una cuenta.
 *  - **`ninguno`** — apagado a propósito: `configurado()` es `false`, así que las pantallas no ofrecen el
 *    canal y el envío automático no lo intenta.
 *
 * El contrato es el mismo que `WhatsAppService`: devuelve la respuesta cruda del proveedor en vez de
 * esconderla, porque es lo único que permite reclamar un mensaje no entregado y depurar un rechazo.
 * Y **el proveedor se elige en una sola variable**: cambiar de operador no toca a los que llaman.
 *
 * ⚠️ El perfil `log` escribe **el texto del mensaje** en el log (con el destino enmascarado): no se usa
 * en producción, donde el dato del paciente no va al log.
 */
class SmsService
{
    /** El de siempre: HTTP a la API de Twilio. */
    public const TWILIO = 'twilio';

    /** Desarrollo: el mensaje al log y `ok`. */
    public const LOG = 'log';

    /** Apagado a propósito. */
    public const NINGUNO = 'ninguno';

    public const PROVEEDORES = [self::TWILIO, self::LOG, self::NINGUNO];

    private string $sid;
    private string $token;
    private string $from;
    private string $proveedor;

    public function __construct()
    {
        $this->sid = (string) config('services.twilio.sid', '');
        $this->token = (string) config('services.twilio.token', '');
        $this->from = (string) config('services.twilio.from', '');

        $elegido = strtolower(trim((string) config('services.sms.proveedor', '')));

        // Sin la variable, el comportamiento es el de siempre (Twilio): las instalaciones que ya mandan
        // SMS no cambian nada al desplegar esto.
        $this->proveedor = in_array($elegido, self::PROVEEDORES, true) ? $elegido : self::TWILIO;
    }

    /** El proveedor con el que va a hablar, ya resuelto (`twilio`, `log` o `ninguno`). */
    public function proveedor(): string
    {
        return $this->proveedor;
    }

    /**
     * ¿El servidor puede mandar SMS? Es la pregunta que hacen las pantallas antes de ofrecer el canal y
     * la que decide el envío automático: `log` cuenta como configurado (es desarrollo), `ninguno` no.
     */
    public function configurado(): bool
    {
        return match ($this->proveedor) {
            self::NINGUNO => false,
            self::LOG => true,
            default => $this->twilioConfigurado(),
        };
    }

    /**
     * @param  string  $destino  Dígitos internacionales sin `+` (lo que devuelve `Telefono::internacional`).
     * @return array{ok: bool, respuesta: array}
     */
    public function enviar(string $destino, string $mensaje): array
    {
        return match ($this->proveedor) {
            self::NINGUNO => [
                'ok' => false,
                'respuesta' => ['error' => 'El envío de SMS está apagado en el servidor.'],
            ],
            self::LOG => $this->porLog($destino, $mensaje),
            default => $this->porTwilio($destino, $mensaje),
        };
    }

    /**
     * El mensaje al log, sin tocar la red: lo que devolvería un proveedor que aceptó.
     *
     * @return array{ok: bool, respuesta: array}
     */
    private function porLog(string $destino, string $mensaje): array
    {
        // El destino enmascarado: en el log no se guarda el teléfono del paciente completo.
        $visible = strlen($destino) > 4 ? str_repeat('*', strlen($destino) - 4) . substr($destino, -4) : '****';

        Log::info("[SMS simulado] a {$visible}: {$mensaje}");

        return ['ok' => true, 'respuesta' => ['proveedor' => self::LOG, 'simulado' => true]];
    }

    /** @return array{ok: bool, respuesta: array} */
    private function porTwilio(string $destino, string $mensaje): array
    {
        if (! $this->twilioConfigurado()) {
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

    /** Las tres variables de Twilio, que son su credencial completa. */
    private function twilioConfigurado(): bool
    {
        return $this->sid !== '' && $this->token !== '' && $this->from !== '';
    }
}
