<?php

namespace App\Clinica\Consulta;

use Illuminate\Support\Facades\DB;

/**
 * **La FUR del control prenatal de una historia** (WEB-6.1): lo que el escritorio usa para completar el eco.
 *
 * Es una lectura chica con una regla del legado detrás. Al entrar a la pestaña *Ginecologico* de la
 * consulta, la ventana busca la FUR del control de embarazo de esa historia y la pone en el campo del eco
 * (`w_consulta_principal.srw:21391-21405`):
 *
 * ```
 * SELECT "pre_natal_observaciones"."fur" INTO :feultima WHERE historia = :historia
 * ```
 *
 * y sólo si la consulta trajo una fila con valor la escribe en el DataWindow (`data[1,28]`, la columna
 * `fur`). Si falla o viene nula, **no toca** lo que había. Es decir: la FUR del eco y la del control
 * prenatal son **la misma** y el médico no la teclea dos veces.
 *
 * Dos diferencias con la fuente, a propósito:
 *
 *  - se acota al `reg_medico` del contexto (la ventana del escritorio no lo hace porque su base es de un
 *    solo médico; en la nube el mismo número de historia puede existir para dos);
 *  - si la historia tiene **varias gestas** (varias filas, cada una con su FUR), se toma la **más
 *    reciente** (`fur desc`): es la del embarazo en curso, que es la que el eco necesita. La fuente no
 *    ordena y toma la primera fila que devuelve el cursor.
 */
final class FurDelControlPrenatal
{
    /** La tabla del control de embarazo donde vive la FUR. */
    public const TABLA = 'pre_natal_observaciones';

    /** La FUR de la historia, en `AAAA-MM-DD`, o `null` si el control prenatal no tiene ninguna. */
    public function de(string $regMedico, int $numhistoria): ?string
    {
        $fur = DB::table(self::TABLA)
            ->where('reg_medico', $regMedico)
            ->where('historia', $numhistoria)
            ->whereNotNull('fur')
            ->orderByDesc('fur')
            ->value('fur');

        return $fur === null ? null : (string) $fur;
    }
}
