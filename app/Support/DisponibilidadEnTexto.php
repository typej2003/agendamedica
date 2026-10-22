<?php

namespace App\Support;

/**
 * El texto con los días que el bot le ofrece a la paciente.
 *
 * **Sin cupos**: cuántos lugares quedan es información interna del consultorio (decisión del usuario,
 * 2026-10-18). Vive acá, y no en el servicio de contexto, por dos razones: es formato puro —no consulta
 * nada— y así el bot lo puede usar sin depender de un método del servicio (que en los tests está mockeado).
 */
final class DisponibilidadEnTexto
{
    private const NUMEROS = [
        1 => '1️⃣',
        2 => '2️⃣',
        3 => '3️⃣',
        4 => '4️⃣',
        5 => '5️⃣',
        6 => '6️⃣',
        7 => '7️⃣',
        8 => '8️⃣',
        9 => '9️⃣',
        10 => '🔟',
    ];

    /**
     * @param  array<string,mixed>  $disponibilidad  lo que devuelve
     *                                               `PacienteAtencionContextService::consultarProximoDiaDisponible`
     */
    public static function de(array $disponibilidad): string
    {
        $texto = self::opciones($disponibilidad);
        $aviso = self::avisoDeUrgencia($disponibilidad);

        return $aviso === '' ? $texto : $aviso . "\n\n" . $texto;
    }

    /**
     * El contacto del consultorio para una urgencia sin horario hoy (`aviso_urgencia`), o ''. Va antes de los días
     * ofrecidos: se suma a la agenda, no la reemplaza.
     *
     * @param  array<string,mixed>  $disponibilidad
     */
    public static function avisoDeUrgencia(array $disponibilidad): string
    {
        return trim((string) ($disponibilidad['aviso_urgencia'] ?? ''));
    }

    /** @param  array<string,mixed>  $disponibilidad */
    private static function opciones(array $disponibilidad): string
    {
        $opciones = array_values(array_filter((array) ($disponibilidad['opciones'] ?? [])));

        // Compatibilidad: la forma vieja traía sólo `fecha_formateada`.
        if ($opciones === [] && ! empty($disponibilidad['fecha_formateada'])) {
            return '📅 *Próximo día con disponibilidad:* ' . $disponibilidad['fecha_formateada'] . "\n\n"
                . '¿Te lo reservo?';
        }

        if ($opciones === []) {
            return '';
        }

        if (count($opciones) === 1) {
            return '📅 *Próximo día con disponibilidad:* ' . self::fecha($opciones[0]) . "\n\n"
                . '¿Te lo reservo?';
        }

        $lineas = ['📅 *Próximos días con disponibilidad:*'];

        foreach ($opciones as $indice => $opcion) {
            $lineas[] = self::numero($indice + 1) . ' ' . self::fecha($opcion);
        }

        $lineas[] = '';
        $lineas[] = '¿Cuál te queda mejor? Escribe el número o el día.';

        return implode("\n", $lineas);
    }

    /** @param  array<string,mixed>|string  $opcion */
    private static function fecha(array|string $opcion): string
    {
        return is_array($opcion)
            ? (string) ($opcion['fecha_formateada'] ?? $opcion['fecha'] ?? '')
            : $opcion;
    }

    private static function numero(int $numero): string
    {
        return self::NUMEROS[$numero] ?? "{$numero}.";
    }
}
