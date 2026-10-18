<?php

namespace App\Clinica\Impresion;

use App\Clinica\Consulta\ImagenDelExamen;
use App\Clinica\Consulta\ImagenDelExamenDeLaConsulta;
use App\Models\Consulta;

/**
 * **La imagen del examen, lista para la hoja de imágenes** (A-13, O-09): lo que se imprime del examen cuando se pide el
 * informe «con imágenes».
 *
 * Sale **sólo el modo que el médico eligió**, que es la decisión de O-09:
 *
 *  - **Imagen predeterminada** → el esquema con el trazo encima, uno por variante marcada (la base elegida es la misma
 *    que se ve en pantalla). Sin trazo no se imprime nada: un esquema en blanco no es un hallazgo.
 *  - **Videocolposcopio** → la **foto real** del estudio, a su tamaño (no estirada al marco de 520×400 del esquema) y con
 *    el trazo encima si lo tiene. La foto sola también se imprime: es el estudio. Lo **no** elegido no sale nunca.
 *
 * Las dos se **incrustan** como data URI (igual que las fotos de un estudio): el archivo clínico no queda expuesto en una
 * URL y la hoja se imprime lo mismo desde la consulta del médico que desde la cita de la secretaría.
 *
 * Es de sólo lectura y no decide qué es un documento: eso lo resuelve `QueEsUnDocumento` antes de pedir la hoja.
 */
final class ImagenesDelExamenParaElInforme
{
    public function __construct(
        private ImagenesParaElInforme $imagenes,
        private ImagenDelExamenDeLaConsulta $estado,
    ) {
    }

    /**
     * @return array<int,array{src:string, base?:?string, trazo?:?string, foto?:bool, descripcion:?string}>
     */
    public function de(Consulta $consulta, string $examen): array
    {
        $estado = $this->estado->de($consulta, $examen);

        return $estado->modo === ImagenDelExamen::MODO_FOTO
            ? $this->laFoto($estado, $examen)
            : $this->elEsquema($estado, $examen);
    }

    /**
     * El modo de la foto: **una** imagen —la elegida—, compuesta con su trazo si lo tiene. La foto va como está: la hoja
     * la muestra a su tamaño y no la estira al marco del esquema.
     *
     * @return array<int,array{src:string, trazo?:?string, foto?:bool, descripcion:?string}>
     */
    private function laFoto(object $estado, string $examen): array
    {
        $foto = $estado->foto;

        // Sin foto, o con una que el servidor no puede leer (quedó en el disco del consultorio, o es un HEIC), no hay nada
        // que imprimir: se respeta la elección y la hoja sale sin imágenes.
        if ($foto === null || ! $foto->enElServidor || $foto->descarga) {
            return [];
        }

        $src = $this->imagenes->deUnaImagen((string) $foto->disco, (string) $foto->archivo);

        if ($src === null) {
            return [];
        }

        $trazo = $estado->trazos[(int) $foto->id] ?? null;

        return [[
            'src' => $src,
            'trazo' => $trazo === null ? null : $this->imagenes->dePngTransparente($trazo),
            'foto' => true,
            'descripcion' => ImagenDelExamen::nombreDeLaFoto($examen),
        ]];
    }

    /**
     * El modo del esquema: una entrada por variante **con trazo**, compuesta como en pantalla (la base elegida + el trazo
     * transparente encima).
     *
     * @return array<int,array{src:string, base:?string, descripcion:?string}>
     */
    private function elEsquema(object $estado, string $examen): array
    {
        $salida = [];

        foreach (ImagenDelExamen::variantes($examen) as $variante) {
            $trazo = $estado->esquema[$variante]['trazo'] ?? null;

            if ($trazo === null) {
                continue;
            }

            // El trazo va **tal cual** (PNG con transparencia): aplanarlo a JPEG taparía el esquema de abajo.
            $src = $this->imagenes->dePngTransparente($trazo);

            if ($src === null) {
                continue;
            }

            $salida[] = [
                'src' => $src,
                // La base que el médico eligió, para que la hoja muestre el dibujo sobre el mismo esquema que la pantalla.
                'base' => asset((string) ImagenDelExamen::ruta($examen, $variante, (string) ($estado->esquema[$variante]['base'] ?? ImagenDelExamen::BASE_POR_DEFECTO))),
                'descripcion' => ImagenDelExamen::etiquetaDeLaVariante($variante),
            ];
        }

        return $salida;
    }
}
