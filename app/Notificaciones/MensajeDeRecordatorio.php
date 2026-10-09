<?php

namespace App\Notificaciones;

/**
 * El texto del recordatorio de una cita, con las etiquetas ya sustituidas.
 *
 * Puerto de `mensajeRecordatorio` del móvil (`recordatorio.dart`): la plantilla es la **del médico
 * de la cita** (`evolucion.plantilla_cita`, que el médico edita desde el móvil — Paso 23), no la de
 * quien tiene la sesión abierta, porque una secretaría puede mandar recordatorios de citas de varios
 * médicos del consultorio y cada uno va con sus palabras. Si el médico no tiene plantilla, vale la de
 * por defecto: es el texto que armaba el endpoint antes de que la plantilla existiera.
 */
final class MensajeDeRecordatorio
{
    public const PLANTILLA_POR_DEFECTO = 'Recordatorio de cita para {paciente}: {fecha} a las {hora}.';

    /** Etiquetas que el editor ofrece (las mismas del móvil). */
    public const ETIQUETAS = ['{paciente}', '{fecha}', '{hora}', '{doctor}', '{centro}'];

    /**
     * @param  array{paciente?:?string,fecha?:?string,hora?:?string,doctor?:?string,centro?:?string}  $datos
     */
    public function armar(?string $plantilla, array $datos): string
    {
        $texto = trim((string) $plantilla) === '' ? self::PLANTILLA_POR_DEFECTO : (string) $plantilla;

        return trim(strtr($texto, [
            '{paciente}' => (string) ($datos['paciente'] ?? 'paciente'),
            '{fecha}'    => (string) ($datos['fecha'] ?? ''),
            '{hora}'     => (string) ($datos['hora'] ?? ''),
            '{doctor}'   => (string) ($datos['doctor'] ?? ''),
            '{centro}'   => (string) ($datos['centro'] ?? ''),
        ]));
    }
}
