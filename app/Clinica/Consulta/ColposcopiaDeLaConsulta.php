<?php

namespace App\Clinica\Consulta;

use App\Models\Consulta;
use Illuminate\Support\Collection;

/**
 * **La colposcopía de una consulta** (WEB-6.5): el texto del informe del examen colposcópico, su lectura y
 * sus plantillas.
 *
 * Es una de las solapas de F6 que cuelgan del **examen** del escritorio (`tab_7` de `tabpage_examen`, la
 * solapa `tabpage_31`, rotulada *Colposcopía*) y la web la carga en `?seccion=evaluacion&pestana=colposcopia`.
 *
 * ## Dónde vive el dato (verificado en la fuente, 2026-10-13)
 *
 * El texto es **uno solo** y el escritorio lo escribe en **dos columnas** al mismo tiempo, en su `ue_guardar`
 * (`w_consulta_principal.srw`):
 *
 *  - **`consultas.observaciones`** — la canónica: es la que **imprime el informe** (`d_colposcopia_1.srd:26` y
 *    `d_colposcopia_2.srd:27` declaran `consultas_observaciones` como la columna del cuerpo) y la que el
 *    guardado escribe primero (`s_obs = tabpage_31.mle_4.text`, `:2757` → `"observaciones" = :s_obs`, `:2836`).
 *    El comentario de la propia ventana lo dice: `SET "colposcopia" = :s_obs` (`:2766`).
 *  - **`examen_fisico_nuevo.colposcopia`** — la copia, para que el resumen del examen ginecológico la lea
 *    (`:2786`). ⚠️ **No confundir**: el nombre de la columna está en la otra tabla que la del texto canónico,
 *    y `consultas.observaciones` **no** es un campo de la evolución (aunque el plan de la web lo listara ahí).
 *
 * La web escribe **las dos**, como el escritorio, y lee la canónica con la copia como respaldo: una fila que
 * sólo tenga una de las dos (una consulta guardada a medias) no pierde el texto. La **citología** es otro
 * examen y otro texto (`consultas.faringe`), y la **histeroscopía** también (`consultas.cuello`).
 *
 * Sin estado: lee con una consulta y arma. La escritura es `App\Actions\Consulta\GuardarColposcopiaDeLaConsulta`.
 */
final class ColposcopiaDeLaConsulta
{
    /** La columna canónica del texto: la de `consultas` que imprime el informe. */
    public const COLUMNA = 'observaciones';

    /** La copia en `examen_fisico_nuevo` (así la llama el escritorio, y así la lee su resumen ginecológico). */
    public const COLUMNA_ESPEJO = 'colposcopia';

    /** El `tipo` de `formato_print` de sus plantillas: el `retrieve('CO')` de la ventana (`:3043`). */
    public const TIPO_PLANTILLA = 'CO';

    public function __construct(private ExamenFisicoDeLaConsulta $examen)
    {
    }

    /**
     * El texto de la colposcopía de una consulta, o `null` si no tiene.
     *
     * Sale de `consultas.observaciones`; si ahí no hay nada, de la copia del examen (el escritorio escribe
     * las dos, pero una fila vieja puede tener sólo una).
     */
    public function de(Consulta $consulta): ?string
    {
        return TextoDeLaConsulta::limpio($consulta->observaciones)
            ?? $this->deLaCopia($consulta);
    }

    /** El texto de la **copia** (`examen_fisico_nuevo.colposcopia`), o `null`. */
    public function deLaCopia(Consulta $consulta): ?string
    {
        $fila = $this->examen->filaDe($consulta);

        return $fila === null ? null : TextoDeLaConsulta::limpio($fila->getAttribute(self::COLUMNA_ESPEJO));
    }

    /**
     * Las plantillas de la colposcopía que tiene cargadas el médico (la lista `formato_print` con
     * `tipo = 'CO'`).
     *
     * @return Collection<int,object{codigo:string, titulo:string, texto:string}>
     */
    public function plantillas(string $regMedico): Collection
    {
        return PlantillasDelTexto::de($regMedico, self::TIPO_PLANTILLA);
    }
}
