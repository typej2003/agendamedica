<?php

namespace App\Clinica\Consulta;

use App\Models\Consulta;
use Illuminate\Support\Collection;

/**
 * **El texto de una solapa del examen** (WEB-6.5): lo que la consulta tiene escrito en la columna de esa solapa
 * (`TextosDelExamen`), con la copia como respaldo cuando existe.
 *
 * Es la lectura de las cuatro solapas de texto —colposcopía, citología, histeroscopía y resultado de
 * exámenes—: las cuatro son **una columna de `consultas`** y ninguna tiene tabla propia, así que cambian la
 * clave y nada más. La escritura es `App\Actions\Consulta\GuardarTextoDelExamen`.
 *
 * La regla de la copia es la de WEB-6.5: la columna canónica manda —es la que imprime el informe— y, si está
 * vacía, se cae a la copia (`examen_fisico_nuevo.colposcopia` hoy), porque una fila vieja puede tener sólo una
 * de las dos.
 *
 * Sin HTTP: recibe la consulta y devuelve texto.
 */
final class TextoDelExamen
{
    public function __construct(private ExamenFisicoDeLaConsulta $examen)
    {
    }

    /**
     * El texto de esa solapa en esa consulta, o `null` si no tiene.
     *
     * @param  string  $clave  una clave de `TextosDelExamen`
     */
    public function de(Consulta $consulta, string $clave): ?string
    {
        $columna = TextosDelExamen::columna($clave);

        if ($columna === null) {
            return null;
        }

        return TextoDeLaConsulta::limpio($consulta->getAttribute($columna))
            ?? $this->deLaCopia($consulta, $clave);
    }

    /** El texto de la **copia** en `examen_fisico_nuevo`, o `null` (sólo la colposcopía tiene copia). */
    public function deLaCopia(Consulta $consulta, string $clave): ?string
    {
        $columna = TextosDelExamen::columnaEspejo($clave);

        if ($columna === null) {
            return null;
        }

        $fila = $this->examen->filaDe($consulta);

        return $fila === null ? null : TextoDeLaConsulta::limpio($fila->getAttribute($columna));
    }

    /**
     * Las plantillas del escritorio de esa solapa (la lista `formato_print` de su `tipo`), o vacío si la solapa
     * no tiene plantillas (la histeroscopía).
     *
     * @return Collection<int,object{codigo:string, titulo:string, texto:string}>
     */
    public function plantillas(string $regMedico, string $clave): Collection
    {
        $tipo = TextosDelExamen::tipoDePlantillas($clave);

        return $tipo === null ? collect() : PlantillasDelTexto::de($regMedico, $tipo);
    }
}
