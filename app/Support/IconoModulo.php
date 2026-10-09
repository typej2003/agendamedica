<?php

namespace App\Support;

/**
 * Icono (Bootstrap Icons) de un módulo de la web clínica, elegido por su `slug`.
 *
 * El menú y el inicio del consultorio salen de `especialidad_modulo`, así que el icono no puede estar
 * escrito en la vista: se resuelve por el slug y lo que no esté en la lista usa uno genérico. Agregar
 * una especialidad o un módulo nuevo no obliga a tocar las pantallas.
 */
class IconoModulo
{
    public static function para(?string $slug): string
    {
        $slug = mb_strtolower((string) $slug);

        return match (true) {
            str_contains($slug, 'agenda'), str_contains($slug, 'cita') => 'bi-calendar3',
            str_contains($slug, 'paciente') => 'bi-people',
            str_contains($slug, 'historia') => 'bi-journal-medical',
            str_contains($slug, 'recipe'), str_contains($slug, 'receta') => 'bi-file-medical',
            str_contains($slug, 'evolucion') => 'bi-activity',
            str_contains($slug, 'ecografia'), str_contains($slug, 'examen') => 'bi-clipboard2-pulse',
            str_contains($slug, 'factura'), str_contains($slug, 'cobro') => 'bi-receipt',
            str_contains($slug, 'embarazo'), str_contains($slug, 'gestacion') => 'bi-gender-female',
            default => 'bi-grid',
        };
    }
}
