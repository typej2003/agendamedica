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
            // La consulta (F4): el mismo icono con el que la web clínica marca la consulta en la historia
            // del paciente (WEB-3.5). El módulo existe en el catálogo pero todavía no se muestra en el
            // menú: se entra por "Atender" (WEB-4.1).
            str_contains($slug, 'consulta') => 'bi-journal-medical',
            str_contains($slug, 'recipe'), str_contains($slug, 'receta') => 'bi-file-medical',
            str_contains($slug, 'evolucion') => 'bi-activity',
            str_contains($slug, 'ecografia'), str_contains($slug, 'examen') => 'bi-clipboard2-pulse',
            str_contains($slug, 'factura'), str_contains($slug, 'cobro') => 'bi-receipt',
            str_contains($slug, 'embarazo'), str_contains($slug, 'gestacion') => 'bi-gender-female',
            // Los módulos propios de pediatría (WEB-7.4.3c y WEB-7.4.4e).
            str_contains($slug, 'crecimiento') => 'bi-graph-up',
            str_contains($slug, 'vacuna') => 'bi-shield-plus',
            default => 'bi-grid',
        };
    }
}
