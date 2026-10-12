<?php

namespace App\Clinica\Catalogos;

use Illuminate\Support\Facades\Route;

/**
 * **Los catálogos clínicos que el médico puede mantener** (WEB-8.1): el índice de la zona "Catálogos" de la web
 * clínica. Cada pantalla de catálogo muestra este índice como un segmentado y la entrada "Catálogos" de la barra
 * lleva al primero.
 *
 * Es **extensible a propósito**: WEB-8.1b suma sus pantallas (diagnósticos, motivos de consulta, exámenes y médicos
 * referidos) agregando una línea a `CATALOGOS`, sin tocar las pantallas que ya existen. Sólo se listan los catálogos
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
    ];

    /**
     * Los catálogos con pantalla, para el segmentado de las pantallas de catálogo.
     *
     * @param  string|null  $actual  el `slug` del catálogo que se está mirando
     * @return array<int,array{texto:string, url:string, activa:bool}>
     */
    public static function pestanas(?string $actual): array
    {
        $pestanas = [];

        foreach (self::existentes() as $slug => $catalogo) {
            $pestanas[] = [
                'texto' => $catalogo['texto'],
                'url' => route($catalogo['ruta']),
                'activa' => $slug === $actual,
            ];
        }

        return $pestanas;
    }

    /** La URL de la entrada "Catálogos" de la barra: el primer catálogo que tiene pantalla. */
    public static function urlDeEntrada(): ?string
    {
        foreach (self::existentes() as $catalogo) {
            return route($catalogo['ruta']);
        }

        return null;
    }

    /** @return array<string,array{texto:string, icono:string, ruta:string}> */
    private static function existentes(): array
    {
        return array_filter(self::CATALOGOS, fn (array $catalogo) => Route::has($catalogo['ruta']));
    }
}
