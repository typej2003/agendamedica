<?php

namespace App\Clinica\Consulta\Vistas;

/**
 * **Las dos pestañas de la sección Récipes** (WEB-5.1b): «Récipes del paciente» y «Récipe de la pareja».
 *
 * Son enlaces —cada pestaña es una URL de la misma sección (`?seccion=recipes` y `…&recipe=pareja`)—, no
 * JavaScript: así funcionan sin script y la pestaña activa se puede compartir y recargar. Sin base de datos.
 */
final class PestanasDeRecipes
{
    public const PACIENTE = 'paciente';

    public const PAREJA = 'pareja';

    public function __construct(private string $urlPaciente, private string $urlPareja)
    {
    }

    /**
     * Las opciones para `<x-ui.segmentado>`.
     *
     * @param  string  $activa  `PACIENTE` o `PAREJA`
     * @return array<int,array{texto:string, url:string, activa:bool}>
     */
    public function opciones(string $activa): array
    {
        $opciones = [['texto' => 'Récipes del paciente', 'url' => $this->urlPaciente, 'activa' => $activa === self::PACIENTE]];

        // Sin URL de la pareja (pediatría, WEB-12.1) no hay segunda pestaña.
        if ($this->urlPareja !== '') {
            $opciones[] = ['texto' => 'Récipe de la pareja', 'url' => $this->urlPareja, 'activa' => $activa === self::PAREJA];
        }

        return $opciones;
    }
}
