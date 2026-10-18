<?php

namespace App\Clinica\Catalogos;

use Illuminate\Support\Facades\Route;

/**
 * **Los catálogos clínicos que el médico puede mantener** (WEB-8.1): la fuente única de qué catálogos existen. La
 * entrada "Catálogos" del menú lateral es un grupo plegable y cada catálogo con pantalla es un submenú suyo
 * (`ArmazonVista::menu()` lo lee de acá; las pantallas ya no repiten un índice de pestañas arriba).
 *
 * Es **extensible a propósito**: WEB-8.1b sumó sus pantallas (diagnósticos, motivos de consulta, exámenes, tipos de examen y
 * médicos referidos) agregando una línea a `CATALOGOS`, sin tocar las pantallas que ya existían. Sólo se listan los catálogos
 * cuya ruta existe: una entrada declarada que todavía no tiene pantalla no se ofrece (no hay enlaces rotos).
 */
final class CatalogosDisponibles
{
    /**
     * `slug => [texto, icono, ruta]`. El orden es el del índice. La ruta es la del listado del catálogo.
     */
    private const CATALOGOS = [
        'medicamentos' => ['texto' => 'Medicamentos', 'icono' => 'bi-capsule', 'ruta' => 'clinica.catalogos.medicamentos'],
        'tratamientos' => ['texto' => 'Tratamientos', 'icono' => 'bi-collection', 'ruta' => 'clinica.catalogos.tratamientos'],
        'diagnosticos' => ['texto' => 'Diagnósticos', 'icono' => 'bi-clipboard2-pulse', 'ruta' => 'clinica.catalogos.diagnosticos'],
        'motivos-consulta' => ['texto' => 'Motivos de consulta', 'icono' => 'bi-chat-left-text', 'ruta' => 'clinica.catalogos.motivos-consulta'],
        'motivos-cita' => ['texto' => 'Motivos de cita', 'icono' => 'bi-tags', 'ruta' => 'clinica.catalogos.motivos-cita'],
        'examenes' => ['texto' => 'Exámenes', 'icono' => 'bi-eyedropper', 'ruta' => 'clinica.catalogos.examenes'],
        'tipos-examen' => ['texto' => 'Tipos de examen', 'icono' => 'bi-tags', 'ruta' => 'clinica.catalogos.tipos-examen'],
        'medicos-referidos' => ['texto' => 'Médicos referidos', 'icono' => 'bi-person-lines-fill', 'ruta' => 'clinica.catalogos.medicos-referidos'],
        'plantillas-texto' => ['texto' => 'Plantillas de texto', 'icono' => 'bi-file-earmark-text', 'ruta' => 'clinica.catalogos.plantillas-texto'],
        // Propio de pediatría (WEB-7.4.4e): el submenú sólo se ofrece a esa especialidad (`especialidad` es un fragmento del slug).
        'vacunas' => ['texto' => 'Vacunas', 'icono' => 'bi-shield-plus', 'ruta' => 'clinica.catalogos.vacunas', 'especialidad' => 'pediatr'],
        'dietas' => ['texto' => 'Dietas', 'icono' => 'bi-egg-fried', 'ruta' => 'clinica.catalogos.dietas', 'especialidad' => 'pediatr'],
    ];

    /** La URL de `/clinica/catalogos` (la entrada histórica de la zona): el primer catálogo que tiene pantalla. */
    public static function urlDeEntrada(): ?string
    {
        foreach (self::existentes() as $catalogo) {
            return route($catalogo['ruta']);
        }

        return null;
    }

    /**
     * Los submenús de "Catálogos" en el menú lateral: un ítem por catálogo con pantalla, con el activo marcado.
     *
     * @param  string|null  $especialidad  el `slug` de la especialidad del contexto (para los catálogos propios de una)
     * @param  string|null  $rutaActual  el nombre de la ruta de la petición (`clinica.catalogos.tratamientos.editar`…):
     *                                   un catálogo está activo en su listado y en todas sus pantallas hijas
     * @return array<int,array{texto:string, icono:string, url:string, activo:bool}>
     */
    public static function submenu(?string $rutaActual, ?string $especialidad = null): array
    {
        $items = [];

        foreach (self::existentes() as $catalogo) {
            // Un catálogo propio de una especialidad sólo se ofrece a ella.
            if (isset($catalogo['especialidad']) && ! str_contains((string) $especialidad, $catalogo['especialidad'])) {
                continue;
            }

            $items[] = [
                'texto' => $catalogo['texto'],
                'icono' => $catalogo['icono'],
                'url' => route($catalogo['ruta']),
                'activo' => $rutaActual !== null
                    && ($rutaActual === $catalogo['ruta'] || str_starts_with($rutaActual, $catalogo['ruta'] . '.')),
            ];
        }

        return $items;
    }

    /** @return array<string,array{texto:string, icono:string, ruta:string}> */
    private static function existentes(): array
    {
        return array_filter(self::CATALOGOS, fn (array $catalogo) => Route::has($catalogo['ruta']));
    }
}
