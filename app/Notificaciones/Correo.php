<?php

namespace App\Notificaciones;

/**
 * Valida que un texto tenga forma de correo antes de intentar mandarlo por SMTP.
 *
 * Puerto de `correoValido` del móvil (`recordatorio.dart`): no valida de más —si el servidor de
 * correo lo rechaza, el envío queda registrado como fallido—, solo descarta lo que seguro no es una
 * dirección (vacío, sin arroba, sin dominio).
 */
final class Correo
{
    public static function valido(?string $correo): ?string
    {
        $limpio = trim((string) $correo);

        if ($limpio === '' || ! filter_var($limpio, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return $limpio;
    }
}
