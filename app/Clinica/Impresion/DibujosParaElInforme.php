<?php

namespace App\Clinica\Impresion;

use App\Clinica\Consulta\DibujosDeColposcopiaDeLaConsulta;
use App\Clinica\Consulta\ImagenDeColposcopia;
use App\Models\Consulta;
use App\Models\DibujoDeColposcopia;

/**
 * **Los dibujos de la colposcopía, listos para la hoja de imágenes** (A-13): los trazos que el médico marcó sobre el
 * cuello uterino, uno por variante, para que salgan **como una foto más** del informe cuando se imprime «con imágenes».
 *
 * Cada uno sale **compuesto**: la imagen base que el médico eligió (`vector` o `escritorio`, la misma que ve en
 * pantalla) con el trazo transparente encima. La base es un archivo público (un SVG o el PNG del escritorio) y el
 * trazo se **incrusta** como data URI, igual que las fotos de un estudio: el archivo clínico no queda expuesto en una
 * URL y la hoja se imprime igual desde la consulta del médico que desde la cita de la secretaría.
 *
 * Es de sólo lectura y no decide qué es un documento: eso lo resuelve `QueEsUnDocumento` antes de pedir la hoja.
 */
final class DibujosParaElInforme
{
    public function __construct(
        private ImagenesParaElInforme $imagenes,
        private DibujosDeColposcopiaDeLaConsulta $dibujos,
    ) {
    }

    /**
     * @return array<int,array{src:string, base:?string, descripcion:?string}> una entrada por variante con trazo, en el
     *                                                                       orden del escritorio (nulípara y multípara)
     */
    public function de(Consulta $consulta): array
    {
        $filas = $this->dibujos->de($consulta);
        $salida = [];

        foreach (DibujoDeColposcopia::VARIANTES as $variante) {
            $dibujo = $filas->get($variante);

            if ($dibujo === null || $dibujo->archivo === null) {
                continue;
            }

            // El trazo va **tal cual** (PNG con transparencia): aplanarlo a JPEG taparía la imagen base.
            $src = $this->imagenes->dePngTransparente($dibujo->archivo);

            if ($src === null) {
                continue;
            }

            $salida[] = [
                'src' => $src,
                // La base que el médico eligió, para que la hoja muestre el dibujo sobre el mismo esquema que la pantalla.
                'base' => asset(ImagenDeColposcopia::ruta($variante, (string) $dibujo->base)),
                'descripcion' => ImagenDeColposcopia::etiqueta($variante),
            ];
        }

        return $salida;
    }
}
