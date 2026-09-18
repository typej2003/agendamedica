<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

/**
 * Envío de SMS por Twilio.
 *
 * El servicio ya está contratado por DDR pero **todavía no está implementado acá**: esta clase
 * es el enganche para que el resto del flujo (endpoint, registro en `notificaciones_cita`,
 * pantalla del app) se pueda construir y probar sin esperar por las credenciales.
 *
 * Mientras `enviar()` no esté implementado devuelve `ok => false` con un motivo claro, y el
 * endpoint responde 501 en vez de fingir que el mensaje salió.
 *
 * TODO: implementar el envío real por la API de Twilio (POST a
 *       https://api.twilio.com/2010-04-01/Accounts/{AccountSid}/Messages.json con auth básica
 *       AccountSid:AuthToken y los campos To/From/Body). Considerar instalar twilio/sdk en vez
 *       de armar la petición a mano.
 * TODO: decidir si el número emisor (`from`) es único de DDR o uno por médico — el legado
 *       guardaba credenciales de SMS por médico en `evolucion` (`sms_user`, `sms_clave`,
 *       `sms_proveedor`, `sms_telefono_llamada`), así que puede que cada consultorio tenga lo
 *       suyo y haya que leerlo de ahí en vez de del `.env`.
 * TODO: contemplar el saldo de mensajes — el legado lleva `evolucion.sms_cantidad_total`.
 *       Definir si se sigue descontando ahí o si el saldo pasa a ser de Twilio.
 */
class TwilioSmsService
{
    public function __construct(
        private ?string $accountSid = null,
        private ?string $authToken = null,
        private ?string $from = null,
    ) {
        $this->accountSid ??= config('services.twilio.account_sid');
        $this->authToken ??= config('services.twilio.auth_token');
        $this->from ??= config('services.twilio.from');
    }

    /**
     * Separado de `estaConfigurado()` a propósito: "faltan credenciales" y "todavía no se
     * programó" son dos problemas distintos y el que llama debe poder distinguirlos.
     *
     * TODO: devolver `true` cuando `enviar()` haga la llamada real.
     */
    public function estaImplementado(): bool
    {
        return false;
    }

    public function estaConfigurado(): bool
    {
        return !empty($this->accountSid) && !empty($this->authToken) && !empty($this->from);
    }

    /**
     * @param string $telefono Destino en formato internacional con `+` (ej: +584121234567).
     *
     * @return array{ok: bool, respuesta: array}
     */
    public function enviar(string $telefono, string $mensaje): array
    {
        // TODO: reemplazar por la llamada real a Twilio. Hasta entonces se deja constancia en el
        //       log para que quede claro que hubo un intento de envío que no salió.
        Log::warning('Intento de envío de SMS con Twilio todavía sin implementar', [
            'destino' => $telefono,
            'longitud_mensaje' => mb_strlen($mensaje),
        ]);

        return [
            'ok' => false,
            'respuesta' => ['error' => 'El envío de SMS por Twilio todavía no está implementado.'],
        ];
    }
}
