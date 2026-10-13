<?php

namespace App\Clinica\Consulta;

use App\Models\Consulta;
use App\Models\EcoObstetrico as EcoObstetricoModelo;

/**
 * **El eco obstétrico de una consulta** (WEB-6.2): su lectura y —cuando el DataWindow lo dicte— su mapa del
 * dato. Es el hermano de `EcoGinecologico` (el eco pélvico, WEB-6.1) y comparte con él la forma:
 *
 *  - **La clave es `numhistoria` + `nroconsulta`** (`historia` + `consulta` en la tabla, las dos con
 *    `key=yes` en el DataWindow del escritorio): **una fila por consulta**, no una por gesta.
 *  - La lectura devuelve el **mismo objeto de valores** que el eco pélvico, `EstudioEcografico`, que es
 *    genérico (envuelve la fila y normaliza los "vacíos" del legado): así la pantalla, la hoja y la regla de
 *    "esto es un documento o no" (`QueEsUnDocumento::ecografia`) se escriben una sola vez.
 *
 * Lo que **este** eco tiene y el pélvico no son tres columnas que lo atan al embarazo: **`gesta`** (el número
 * de la gesta que se estaba siguiendo), **`fur`** (la FUR, que el escritorio copia del control prenatal) y
 * **`eg`** (la edad gestacional, guardada como texto). Ver `App\Clinica\Embarazo\` para de dónde salen la FUR
 * y la gesta (`FurDelControlPrenatal`, `GestasDeLaHistoria`).
 *
 * Los **estudios** de este ítem son varios —el **primer trimestre** (`eco_obstetrico`) y el **tercero**
 * (`eco_obstetrico_tercer`, con sus variantes `_o`/`_2`)— y cada uno tiene su tabla: esta clase es la del
 * **primer trimestre**, que es la primera que se implementa. El **Doppler** y el **ecocardiograma fetal**
 * son WEB-6.3.
 */
final class EcoObstetrico
{
    /** La tabla del eco obstétrico del primer trimestre. */
    public const TABLA = 'eco_obstetrico';

    /** La columna de la historia, como en el DataWindow (`key=yes`). */
    public const COLUMNA_HISTORIA = 'historia';

    /** La columna de la consulta: **es parte de la clave** (`consulta`, `NOT NULL`). */
    public const COLUMNA_CONSULTA = 'consulta';

    /**
     * El estudio de una consulta: sus valores, normalizados, o el objeto **vacío** si esa consulta no tiene
     * eco obstétrico (la pantalla lo muestra en blanco y la hoja no se ofrece).
     */
    public function de(Consulta $consulta): EstudioEcografico
    {
        $fila = $this->filaDe($consulta);

        return new EstudioEcografico($fila === null ? [] : $fila->getAttributes());
    }

    /**
     * La fila del eco obstétrico de una consulta, o `null`.
     *
     * La consulta ya viene resuelta contra el contexto (el médico y su consultorio), así que acá se busca por
     * su clave exacta: el `reg_medico` de la consulta y sus `numhistoria`/`nroconsulta`.
     */
    public function filaDe(Consulta $consulta): ?EcoObstetricoModelo
    {
        return EcoObstetricoModelo::query()
            ->where('reg_medico', $consulta->reg_medico)
            ->where(self::COLUMNA_HISTORIA, (int) $consulta->numhistoria)
            ->where(self::COLUMNA_CONSULTA, (int) $consulta->nroconsulta)
            ->first();
    }

    /**
     * La fila del eco de una consulta **para escribir**: la que ya existe o una nueva con su clave puesta
     * (`reg_medico`, `historia` y `consulta`) para que la Action sólo tenga que completar lo que el médico
     * cargó. Es el mismo camino del eco pélvico: la clave la arma el mapa del dato y no el formulario.
     */
    public function filaParaEscribir(Consulta $consulta): EcoObstetricoModelo
    {
        $fila = $this->filaDe($consulta);

        if ($fila !== null) {
            return $fila;
        }

        return new EcoObstetricoModelo([
            'reg_medico' => $consulta->reg_medico,
            self::COLUMNA_HISTORIA => (int) $consulta->numhistoria,
            self::COLUMNA_CONSULTA => (int) $consulta->nroconsulta,
        ]);
    }
}
