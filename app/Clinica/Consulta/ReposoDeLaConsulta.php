<?php

namespace App\Clinica\Consulta;

use App\Models\Consulta;
use App\Models\ReposoPaciente;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Throwable;

/**
 * **El reposo de una consulta** (WEB-5.3a, pestaña *Reposos* de las *Solicitudes*, ítem 11 del panel) y la cuenta de
 * sus fechas.
 *
 * ## Uno por consulta (verificado en la fuente y en el esquema)
 *
 * `reposo_paciente` se llavea por `nrohistoria` + `nroconsulta` (`PRIMARY KEY ("nrohistoria","nroconsulta")` en
 * `esquemas-asa/*.sql`; `d_reposo_paci.srd` declara las dos como `key=yes`) y la pestaña trabaja **la fila de esa
 * consulta** (`dw_22.retrieve(historia, consulta)`, `w_consulta_principal.srw:3135`): **un reposo por consulta**, no
 * una lista. La base de desarrollo lo confirma: 79.538 filas, 79.538 `(médico, historia, consulta)` distintos.
 *
 * Los campos del formulario son los de `d_reposo_paci.srd`: *Días de reposo* (`numdias`, máscara `###`), *Fecha de
 * inicio* (`fdesde`), *Finalización* (calculada, `relativedate(fdesde, numdias-1)`) y *Justificación*
 * (`obser_reposo`). **`codereposo` no tiene campo en la pantalla**: el escritorio lo fija en `'M'` al crear la fila
 * (`:3140`) y es `'M'` en las 79.538 filas reales, así que no hay catálogo de tipos de reposo y la web también lo
 * escribe `'M'` sin ofrecerlo (un valor que no fuera `'M'` —no existe hoy— se respetaría y no se borra).
 *
 * ## La cuenta de las fechas
 *
 * Es la del escritorio (`d_reposo_1.srd`, los `compute_7_1` y `compute_8_1`) y la del móvil (`ReposoMedico.hasta` /
 * `.reintegro`): **el día de inicio cuenta como el primer día de reposo**.
 *
 *  - **Hasta** (último día de reposo) = `fdesde + numdias − 1`.
 *  - **Reintegro a sus labores** = `fdesde + numdias` (el día siguiente al último).
 *
 * Con 1 día desde el 10/10: hasta el 10/10 y reintegro el 11/10. Se cuenta por calendario (`addDays`), no sumando
 * horas, para que un cambio de horario no corra el día.
 *
 * Es de **sólo lectura** (más la cuenta): la escritura es `App\Actions\Consulta\GuardarReposoDeLaConsulta`.
 */
final class ReposoDeLaConsulta
{
    /** El `codereposo` con el que el escritorio crea el reposo. */
    public const CODIGO = 'M';

    /** El tope de días que acepta la web: un año. El legado no pone tope (máscara `###`), el más largo real es de 84. */
    public const DIAS_MAXIMO = 365;

    /** La fila de `reposo_paciente` de la consulta, o `null` si no tiene. */
    public function fila(Consulta $consulta): ?ReposoPaciente
    {
        return ReposoPaciente::where('reg_medico', $consulta->reg_medico)
            ->where('nrohistoria', (int) $consulta->numhistoria)
            ->where('nroconsulta', (int) $consulta->nroconsulta)
            ->orderBy('id')
            ->first();
    }

    /** ¿La fila es un reposo de verdad o la plantilla de 1 día que crea el escritorio? Ver `QueEsUnDocumento`. */
    public function esReal(?ReposoPaciente $fila): bool
    {
        return $fila !== null && QueEsUnDocumento::reposo($fila->numdias, $fila->obser_reposo);
    }

    /**
     * El día de inicio de una fila como `AAAA-MM-DD`; si la fila no lo tiene o es basura (hay 8 filas sin `fdesde` en el
     * legado y las fechas `0000-00-*`), el `$respaldo` (el día de la consulta).
     *
     * No usa `FechaClinica::paraInput`, que descarta las fechas futuras (están bien para un nacimiento): un reposo que
     * **empieza mañana** es normal.
     *
     * @param  mixed  $fdesde  el valor de la columna (texto o fecha)
     */
    public static function fechaDeInicio($fdesde, string $respaldo): string
    {
        $texto = trim((string) $fdesde);

        if ($texto === '' || str_starts_with($texto, '0000') || strcasecmp($texto, 'null') === 0) {
            return $respaldo;
        }

        try {
            $fecha = Carbon::parse($texto);
        } catch (Throwable $e) {
            return $respaldo;
        }

        return $fecha->year < 1900 ? $respaldo : $fecha->toDateString();
    }

    /** El último día de reposo: `desde + días − 1`. */
    public static function hasta(CarbonInterface $desde, int $dias): Carbon
    {
        return Carbon::instance($desde)->startOfDay()->addDays($dias - 1);
    }

    /** El día de reintegro a las labores: `desde + días`. */
    public static function reintegro(CarbonInterface $desde, int $dias): Carbon
    {
        return Carbon::instance($desde)->startOfDay()->addDays($dias);
    }
}
