<?php

namespace App\Clinica\Consulta;

use App\Models\Consulta;
use App\Models\EcoObstetricoTercer;

/**
 * **El eco obstétrico del tercer trimestre de una consulta** (WEB-6.2): el hermano mayor del de primer
 * trimestre (`EcoObstetrico`), con la misma forma y otra tabla.
 *
 * La clave es la misma —**`reg_medico` + `historia` + `consulta`** (`numhistoria` + `nroconsulta`), una fila
 * por consulta— y el objeto de valores también (`EstudioEcografico`, genérico). Lo que cambia es la tabla
 * (`eco_obstetrico_tercer`) y, sobre todo, **cuántas columnas**: 69, en los tres bloques del escritorio
 * (**biometría** `bio_*`, **anatomía** `ana_*` y **función** `fun_*`) más `gestacion`, `situacion`, `dorso`,
 * `presentacion`, `conclusion`, `observacion` y las dos del escritorio que este estudio tiene de propio:
 * **`gemelo`** (el embarazo múltiple) y **`puro_texto`** (el informe en texto libre).
 *
 * Las variantes `eco_obstetrico_tercer_o` y `_tercer_2_o` son **el mismo estudio sin `gemelo` ni
 * `puro_texto`** (vacías en el dump): si alguna vez hay que imprimir sus datos, se resuelven con su propia
 * clase o con el mismo mapa parametrizado, no mezclándolas con esta.
 */
final class EcoObstetricoTercero
{
    /** La tabla del eco obstétrico del tercer trimestre. */
    public const TABLA = 'eco_obstetrico_tercer';

    /** La columna de la historia (parte de la clave). */
    public const COLUMNA_HISTORIA = 'historia';

    /** La columna de la consulta: **es parte de la clave** (`consulta`, `NOT NULL`). */
    public const COLUMNA_CONSULTA = 'consulta';

    /** El estudio de una consulta, o el objeto vacío si esa consulta no lo tiene. */
    public function de(Consulta $consulta): EstudioEcografico
    {
        $fila = $this->filaDe($consulta);

        return new EstudioEcografico($fila === null ? [] : $fila->getAttributes());
    }

    /** La fila de `eco_obstetrico_tercer` de una consulta, o `null`. */
    public function filaDe(Consulta $consulta): ?EcoObstetricoTercer
    {
        return EcoObstetricoTercer::query()
            ->where('reg_medico', $consulta->reg_medico)
            ->where(self::COLUMNA_HISTORIA, (int) $consulta->numhistoria)
            ->where(self::COLUMNA_CONSULTA, (int) $consulta->nroconsulta)
            ->first();
    }

    /** La fila para escribir: la que ya existe o una nueva con su clave puesta. */
    public function filaParaEscribir(Consulta $consulta): EcoObstetricoTercer
    {
        $fila = $this->filaDe($consulta);

        if ($fila !== null) {
            return $fila;
        }

        return new EcoObstetricoTercer([
            'reg_medico' => $consulta->reg_medico,
            self::COLUMNA_HISTORIA => (int) $consulta->numhistoria,
            self::COLUMNA_CONSULTA => (int) $consulta->nroconsulta,
        ]);
    }
}
