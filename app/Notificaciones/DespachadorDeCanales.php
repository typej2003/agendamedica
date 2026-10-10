<?php

namespace App\Notificaciones;

use App\Models\Medico;
use App\Services\SmsService;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;

/**
 * **El envío de un mensaje por cualquiera de los tres canales**, sin saber de qué mensaje se trata.
 *
 * Es la parte que comparten el recordatorio de cita (`App\Actions\Notificaciones\EnviarRecordatorio`) y
 * el saludo de cumpleaños (`App\Actions\Cumpleanos\EnviarSaludoDeCumpleanos`): el saludo se manda **por
 * los mismos tres canales, con las mismas credenciales del servidor y el mismo registro de la respuesta
 * del proveedor**, y esa parte no se escribe dos veces (PLAN-WEB.md, R3).
 *
 * No decide nada del negocio: quién recibe, por qué canal y con qué texto lo decidieron los que llaman.
 * Acá solo está "cómo sale cada canal":
 *
 *  - **WhatsApp** sale con la **plantilla aprobada por Meta** (`notificacion_paciente`, con el nombre del
 *    paciente como único parámetro). El texto del médico se registra en la notificación, pero **no es lo
 *    que viaja**: fuera de la ventana de 24 h Meta solo acepta plantillas aprobadas. Es una limitación
 *    del canal, no una decisión de producto, y por eso el que llama tiene que saberlo.
 *  - **SMS** sale por Twilio con la credencial del servidor.
 *  - **Correo** sale por el SMTP del servidor con la clase de `Mailable` que le pasan, que es lo único
 *    que cambia entre un mensaje y otro (asunto y cuerpo). Las dos clases aceptan la misma forma —
 *    cuerpo, paciente, doctor, centro, remitente—, así que el despachador no necesita saber cuál es.
 *
 * ⚠️ `WhatsAppService` tipa sus credenciales como `string`: se verifica la configuración **antes de
 * instanciarlo**, o sin `WHATSAPP_TOKEN` el constructor tira un `TypeError` en vez del 503 que
 * corresponde.
 */
final class DespachadorDeCanales
{
    /** Nombre exacto de la plantilla aprobada en Meta, la única que se puede mandar por WhatsApp. */
    public const PLANTILLA_WHATSAPP = 'notificacion_paciente';

    /**
     * @param  string  $correo  La clase de `Mailable` con la que sale el canal correo.
     *
     * @return array{ok: bool, respuesta: array}
     *
     * @throws ExcepcionDeEnvio  si el proveedor del canal no está configurado en el servidor.
     */
    public function enviar(
        string $canal,
        string $destino,
        string $texto,
        string $nombrePaciente,
        ?Medico $medico,
        ?string $centro,
        string $correo
    ): array {
        return match ($canal) {
            Canal::WHATSAPP => $this->porWhatsApp($destino, $nombrePaciente),
            Canal::SMS      => $this->porSms($destino, $texto),
            Canal::CORREO   => $this->porCorreo($destino, $texto, $nombrePaciente, $medico, $centro, $correo),
            default         => throw new InvalidArgumentException("Canal de notificación desconocido: {$canal}."),
        };
    }

    /** @return array{ok: bool, respuesta: array} */
    private function porWhatsApp(string $destino, string $nombrePaciente): array
    {
        if (! config('services.whatsapp.token') || ! config('services.whatsapp.phone_number_id')) {
            throw new ExcepcionDeEnvio('El envío por WhatsApp no está configurado en el servidor.', 503);
        }

        $respuesta = app(WhatsAppService::class)->sendTemplate(
            $destino,
            self::PLANTILLA_WHATSAPP,
            [$nombrePaciente],
        );

        return ['ok' => isset($respuesta['messages']), 'respuesta' => $respuesta];
    }

    /** @return array{ok: bool, respuesta: array} */
    private function porSms(string $destino, string $texto): array
    {
        $sms = app(SmsService::class);

        if (! $sms->configurado()) {
            throw new ExcepcionDeEnvio('El envío de SMS no está configurado en el servidor.', 503);
        }

        return $sms->enviar($destino, $texto);
    }

    /**
     * El correo, con el **médico como remitente** (para que el paciente le conteste a él y no al
     * servidor) y el asunto y el cuerpo que traiga la clase de `Mailable`.
     *
     * @return array{ok: bool, respuesta: array}
     */
    private function porCorreo(
        string $destino,
        string $texto,
        string $nombrePaciente,
        ?Medico $medico,
        ?string $centro,
        string $correo
    ): array {
        $remitente = Correo::valido($medico->email ?? null);
        $nombre = $medico?->nombreMostrar ?? '';

        try {
            Mail::to($destino)->send(new $correo(
                cuerpo: $texto,
                paciente: $nombrePaciente,
                doctor: $nombre !== '' ? $nombre : null,
                centro: $centro,
                remitente: $remitente,
                nombreRemitente: $nombre !== '' ? $nombre : null,
            ));
        } catch (\Throwable $e) {
            return ['ok' => false, 'respuesta' => ['error' => $e->getMessage()]];
        }

        return ['ok' => true, 'respuesta' => ['to' => $destino, 'from' => $remitente]];
    }
}
