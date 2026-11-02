<?php

namespace App\Cumpleanos;

/**
 * **El motivo legible de un envío fallido** (O-51, 2026-11-02): traduce la columna `respuesta` de
 * `notificaciones_cumple` —que guarda la contestación cruda del proveedor— a una línea que se pueda
 * mostrar en la pantalla.
 *
 * El JSON no es el mismo en los tres canales: el correo devuelve `{"error": "..."}`; Twilio, `{"code":
 * 21211, "message": "..."}`; WhatsApp, un `{"error": {"message": ...}}` anidado. Se buscan esas claves
 * primero —también dentro del `error`, que es donde Meta pone lo útil— y, si no es JSON, se usa el texto
 * tal cual (es lo que deja una excepción de SMTP).
 *
 * Lo que **no** se hace es volcar el JSON crudo: el médico necesita «el número no es válido», no
 * `{"code":21211,...}`. Se recorta a una línea corta porque el detalle completo sigue en la tabla para
 * quien tenga que reclamar.
 *
 * Sin estado y sin base de datos.
 */
final class MotivoDelFallo
{
    /** El largo máximo del motivo que se muestra (una línea de la tabla). */
    public const LARGO = 160;

    /** Las claves que, en ese orden, llevan el motivo en las respuestas de los proveedores. */
    private const CLAVES = ['error', 'message', 'motivo', 'detail'];

    public static function de(?string $respuesta): ?string
    {
        $texto = trim((string) $respuesta);

        if ($texto === '') {
            return null;
        }

        $datos = json_decode($texto, true);

        // No es JSON: es el texto crudo del proveedor o el mensaje de una excepción.
        if (! is_array($datos)) {
            return self::recortar($texto);
        }

        $motivo = self::delArreglo($datos);

        return $motivo === null ? null : self::recortar($motivo);
    }

    /**
     * El motivo dentro de una respuesta ya decodificada: la primera clave conocida con texto, o `code` si
     * no hay ninguna.
     *
     * @param  array<mixed>  $datos
     */
    private static function delArreglo(array $datos): ?string
    {
        foreach (self::CLAVES as $clave) {
            if (! array_key_exists($clave, $datos)) {
                continue;
            }

            $valor = $datos[$clave];

            // WhatsApp mete el mensaje dentro de `error`: se baja hasta el fondo.
            if (is_array($valor)) {
                $anidado = self::delArreglo($valor);

                if ($anidado !== null) {
                    return $anidado;
                }

                continue;
            }

            if (is_scalar($valor) && trim((string) $valor) !== '') {
                return self::conCodigo(trim((string) $valor), $datos);
            }
        }

        if (isset($datos['code']) && is_scalar($datos['code']) && (string) $datos['code'] !== '') {
            return 'Código ' . $datos['code'] . ' del proveedor';
        }

        return null;
    }

    /**
     * El mensaje con su código entre paréntesis cuando el proveedor lo manda aparte: «The 'To' number is
     * not a valid phone number (código 21211)».
     *
     * @param  array<mixed>  $datos
     */
    private static function conCodigo(string $mensaje, array $datos): string
    {
        $codigo = $datos['code'] ?? null;

        if (! is_scalar($codigo) || (string) $codigo === '' || str_contains($mensaje, 'código')) {
            return $mensaje;
        }

        return $mensaje . ' (código ' . $codigo . ')';
    }

    /** Una sola línea, sin saltos ni espacios repetidos, y recortada si es muy larga. */
    private static function recortar(string $texto): string
    {
        $limpio = trim((string) preg_replace('/\s+/u', ' ', $texto));

        if (mb_strlen($limpio) <= self::LARGO) {
            return $limpio;
        }

        return mb_substr($limpio, 0, self::LARGO - 1) . '…';
    }
}
