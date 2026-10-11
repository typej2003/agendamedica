<?php

namespace App\Clinica\Impresion;

/**
 * **Reparte los medicamentos de un récipe en hojas** (WEB-5.2): decide el tamaño de la letra y, si no entran en
 * una, dónde sigue el récipe en la hoja siguiente.
 *
 * ## La regla (decisión de producto: "que entre en una hoja; si no, la segunda hoja")
 *
 *  1. La letra del cuerpo tiene **tres escalones**: **10 pt**, **9 pt** y **8 pt**. Más chica que 8 pt una receta
 *     deja de leerse en la mano de un paciente mayor (el móvil baja a 8 pt y ahí se queda).
 *  2. Para cada escalón se miden los medicamentos (`MedidorDeTexto`, el alto de cada uno según cuántas líneas
 *     toma su nombre y su indicación) y se van acomodando **en orden** en las hojas que hagan falta, contra el
 *     alto que queda en la mitad (`MedidasDeLaMitad::altoDelCuerpo`).
 *  3. **Gana el escalón con menos hojas; a igualdad, la letra más grande.** O sea: si todo entra en una hoja a
 *     9 pt, sale a 9 pt aunque a 10 pt necesitara dos; y si hacen falta dos hojas de todos modos, sale a 10 pt si
 *     con eso siguen siendo dos.
 *  4. **Un medicamento nunca se parte entre dos hojas**: o entra completo o pasa a la siguiente. Uno solo que no
 *     cabe ni solo en una hoja (una indicación kilométrica del legado) se queda solo en la suya.
 *  5. Cada hoja repite el membrete, los datos del paciente, la firma y el pie: **cada hoja es un récipe válido**.
 *
 * Para el membrete típico (logo mediano a la izquierda, pie de tres líneas) el cuerpo mide ~102 mm: entran de 6
 * a 10 medicamentos por hoja según cuántas líneas lleve cada indicación, y un récipe de 12 sale en **dos hojas**.
 *
 * En el modo "Rp. + Indicaciones" los **dos lados llevan los mismos medicamentos de la misma hoja** (la hoja 2
 * es la continuación de las dos mitades), y el reparto lo manda el lado que más ocupe: el de las indicaciones,
 * salvo que un medicamento no tenga indicación, caso en que los dos miden casi igual y el de "Rp." tiene menos
 * alto por la dirección.
 *
 * Es una clase **sin estado y sin base de datos**.
 */
final class DistribuidorDeRecipe
{
    /** Los tamaños de letra del cuerpo, del más grande al más chico, en puntos. */
    public const ESCALONES = [10, 9, 8];

    /** El ancho del cuerpo: el útil menos la sangría (`.recipe-cuerpo`). */
    private const ANCHO_DEL_CUERPO_MM = MedidasDeLaMitad::ANCHO_UTIL_MM - MedidasDeLaMitad::SANGRIA_DEL_CUERPO_MM;

    /** La columna de la cantidad en "Rp." (`.recipe-cantidad`). */
    private const COLUMNA_DE_CANTIDAD_MM = 9.0;

    /** El aire debajo de cada medicamento (`.recipe-rp-fila` y `.recipe-indicacion-fila`). */
    private const AIRE_EN_RP_MM = 1.8;

    private const AIRE_EN_INDICACIONES_MM = 2.6;

    public function __construct(
        private MedidasDeLaMitad $medidas = new MedidasDeLaMitad(),
        private MedidorDeTexto $medidor = new MedidorDeTexto(),
    ) {
    }

    /**
     * @return array{puntos:int, hojas:array<int,array<int,int>>}  el tamaño de letra y, por hoja, los índices de
     *                                                              `$documento->items` que lleva
     */
    public function distribuir(Membrete $membrete, DocumentoDeRecipe $documento, OpcionesDeRecipe $opciones): array
    {
        $nombre = $documento->persona['nombre'];
        $generico = $opciones->esGenerico();

        $capacidadDeIndicaciones = $this->medidas->altoDelCuerpo($membrete, $nombre, null);
        $capacidadDeRp = $opciones->conRpEIndicaciones()
            ? $this->medidas->altoDelCuerpo($membrete, $nombre, $documento->persona['direccion'])
            : null;

        $mejor = null;

        foreach (self::ESCALONES as $puntos) {
            $costos = [];
            foreach ($documento->items as $indice => $item) {
                $costos[$indice] = [
                    'indicaciones' => $this->costoEnIndicaciones($item, $generico, $puntos),
                    'rp' => $capacidadDeRp === null ? 0.0 : $this->costoEnRp($item, $generico, $puntos),
                ];
            }

            $hojas = $this->acomodar($costos, $capacidadDeIndicaciones, $capacidadDeRp);

            // Menos hojas gana; a igualdad se queda el primero que se vio, que es la letra más grande.
            if ($mejor === null || count($hojas) < count($mejor['hojas'])) {
                $mejor = ['puntos' => $puntos, 'hojas' => $hojas];
            }
        }

        return $mejor;
    }

    /**
     * Acomoda los medicamentos en orden: cada uno va a la hoja actual si entra en las dos mitades, y si no,
     * abre una hoja nueva. Siempre hay al menos una hoja.
     *
     * @param  array<int,array{indicaciones:float, rp:float}>  $costos
     * @return array<int,array<int,int>>
     */
    private function acomodar(array $costos, float $capacidadDeIndicaciones, ?float $capacidadDeRp): array
    {
        $hojas = [[]];
        $enIndicaciones = 0.0;
        $enRp = 0.0;

        foreach ($costos as $indice => $costo) {
            $cabe = $enIndicaciones + $costo['indicaciones'] <= $capacidadDeIndicaciones
                && ($capacidadDeRp === null || $enRp + $costo['rp'] <= $capacidadDeRp);

            $hojaActual = count($hojas) - 1;

            // La hoja vacía recibe al medicamento aunque no quepa: no se parte nunca, y abrir otra hoja para
            // dejar la anterior vacía no arregla nada.
            if (! $cabe && $hojas[$hojaActual] !== []) {
                $hojas[] = [];
                $hojaActual++;
                $enIndicaciones = 0.0;
                $enRp = 0.0;
            }

            $hojas[$hojaActual][] = $indice;
            $enIndicaciones += $costo['indicaciones'];
            $enRp += $costo['rp'];
        }

        return $hojas;
    }

    /** El alto de un medicamento en la mitad "Indicaciones": el nombre en negrita y debajo su indicación. */
    private function costoEnIndicaciones(ItemImpreso $item, bool $generico, int $puntos): float
    {
        $linea = $puntos * MedidasDeLaMitad::INTERLINEA * MedidorDeTexto::MM_POR_PUNTO;

        $lineas = max($this->medidor->lineas($item->nombre($generico), self::ANCHO_DEL_CUERPO_MM, $puntos, true), 1)
            + $this->medidor->lineas((string) $item->indicaciones, self::ANCHO_DEL_CUERPO_MM, $puntos);

        return $lineas * $linea + self::AIRE_EN_INDICACIONES_MM;
    }

    /** El alto de un medicamento en la mitad "Rp.": la cantidad al lado del nombre. */
    private function costoEnRp(ItemImpreso $item, bool $generico, int $puntos): float
    {
        $linea = $puntos * MedidasDeLaMitad::INTERLINEA * MedidorDeTexto::MM_POR_PUNTO;
        $lineas = max($this->medidor->lineas($item->nombre($generico), self::ANCHO_DEL_CUERPO_MM - self::COLUMNA_DE_CANTIDAD_MM, $puntos, true), 1);

        return $lineas * $linea + self::AIRE_EN_RP_MM;
    }
}
