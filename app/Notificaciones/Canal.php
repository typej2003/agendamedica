<?php

namespace App\Notificaciones;

/**
 * Por dónde sale un recordatorio (WEB-2.6).
 *
 * Los tres canales existen en las dos superficies, pero **no por el mismo camino**: el escritorio
 * manda el SMS por un gateway en la PC del consultorio (imposible para una web online) y el correo
 * por SOAP a `ddrsistemas.com`. La web los manda **desde el servidor**: Twilio para el SMS
 * (`services.twilio`, credencial del servidor) y el SMTP del servidor para el correo.
 *
 * El móvil conserva el SMS nativo de Android y los enlaces `wa.me`/`mailto:` — son caminos distintos
 * para la misma acción, no una divergencia de reglas: el texto, la plantilla y el registro del envío
 * son los mismos (`App\Actions\Notificaciones\EnviarRecordatorio`).
 */
final class Canal
{
    public const SMS = 'sms';
    public const WHATSAPP = 'whatsapp';
    public const CORREO = 'correo';

    /** @return list<string> */
    public static function todos(): array
    {
        return [self::SMS, self::WHATSAPP, self::CORREO];
    }

    public static function existe(?string $canal): bool
    {
        return in_array($canal, self::todos(), true);
    }

    /**
     * El canal necesita el **correo** del paciente; los otros dos, un teléfono.
     * Es la misma regla que el móvil (`recordatorio.dart`, `CanalRecordatorio.usaCorreo`).
     */
    public static function usaCorreo(?string $canal): bool
    {
        return $canal === self::CORREO;
    }

    public static function etiqueta(?string $canal): string
    {
        return match ($canal) {
            self::SMS      => 'SMS',
            self::WHATSAPP => 'WhatsApp',
            self::CORREO   => 'Correo',
            default        => (string) $canal,
        };
    }

    /** @return array<string,string> canal => etiqueta, en el orden en que se ofrecen. */
    public static function etiquetas(): array
    {
        $etiquetas = [];
        foreach (self::todos() as $canal) {
            $etiquetas[$canal] = self::etiqueta($canal);
        }

        return $etiquetas;
    }
}
