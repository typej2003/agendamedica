<?php

namespace App\Notificaciones;

/**
 * Normaliza el teléfono de un paciente al formato internacional de los proveedores.
 *
 * Puerto de `DoctorisimoMobile/lib/core/utils/telefono.dart` (`telefonoParaSms`), que es el que ya
 * usan el SMS nativo del móvil y el enlace `wa.me`. Devuelve los dígitos **sin** el `+`: es lo que
 * pide la API de Meta y lo que Twilio acepta con el `+` delante (`SmsService` lo agrega).
 *
 * ⚠️ Asume **Venezuela (+58)**, que es como están guardados los teléfonos del legado
 * (`04121234567`). El proyecto contempla multi-país (Docs/Wiki/00-contexto-negocio.md), así que esto
 * hay que parametrizarlo por médico/país antes de vender fuera de Venezuela — queda anotado en el
 * ROADMAP.
 */
final class Telefono
{
    public static function internacional(?string $telefono): ?string
    {
        $digitos = preg_replace('/\D/', '', (string) $telefono);

        if ($digitos === '') {
            return null;
        }

        // Ya viene internacional: 58 + operadora (3) + número (7).
        if (str_starts_with($digitos, '58') && strlen($digitos) === 12) {
            return $digitos;
        }

        // Local con 0 inicial: 0412 1234567.
        if (str_starts_with($digitos, '0') && strlen($digitos) === 11) {
            return '58' . substr($digitos, 1);
        }

        // Local sin 0: 412 1234567.
        if (strlen($digitos) === 10 && str_starts_with($digitos, '4')) {
            return '58' . $digitos;
        }

        // Cualquier otra cosa no se adivina: mejor que el médico corrija el teléfono a que el
        // mensaje se vaya a un número equivocado.
        return null;
    }

    /** Cómo se muestra un teléfono normalizado, para la pantalla: `+58 412-1234567`. */
    public static function mostrar(?string $telefono): string
    {
        $digitos = self::internacional($telefono);

        if ($digitos === null) {
            return trim((string) $telefono);
        }

        return '+' . substr($digitos, 0, 2) . ' ' . substr($digitos, 2, 3) . '-' . substr($digitos, 5);
    }
}
