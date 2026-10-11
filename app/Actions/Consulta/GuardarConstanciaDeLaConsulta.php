<?php

namespace App\Actions\Consulta;

use App\Clinica\Consulta\ConstanciaDeLaConsulta;
use App\Clinica\Consulta\TextoDeLaConsulta;
use App\Models\ConstanciaObs;
use App\Models\Consulta;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * **Guardar la constancia de una consulta** (WEB-5.3a): la observación del médico y, si los editó, los diagnósticos.
 * También es el «Eliminar»: guardar con los dos campos vacíos es quitar lo propio de la constancia.
 *
 * ## Qué escribe y en qué columna (`ConstanciaDeLaConsulta` tiene las citas de la fuente)
 *
 *  - **`observacion01`** = la observación (es lo que escribe `ue_salvar_obs_contancia` del escritorio).
 *  - **`observacion`** = los diagnósticos **sólo si el médico los editó**: si el texto que llega es el que arma la
 *    consulta (sus diagnósticos, a la manera de `gfsDiagnosticos`), se guarda `NULL` y la constancia sigue trayendo los
 *    de la consulta. Es la única columna que el escritorio nunca escribe, así que no pisa nada suyo.
 *
 * ## Cuándo hay documento
 *
 * **No se crea una fila vacía.** El escritorio ya crea la suya al abrir la consulta (con todo en `NULL`); la web, si la
 * consulta no tiene fila y el médico no escribió nada propio, **no escribe nada** (`ResultadoDeSolicitud::sinCambios`).
 * Una constancia sin nada propio no es un documento (`QueEsUnDocumento::constancia`): su texto es fijo y lo arma la
 * consulta. Al quitar lo propio, la fila **queda** (con `NULL`), como la deja el escritorio.
 *
 * Deja su rastro en `sync_changes` (`source = web`) por columna, con el last-write-wins del sync, y cambia `updated_at`
 * (el móvil baja `constancia_obs` por delta).
 */
final class GuardarConstanciaDeLaConsulta
{
    use EscribeConRastro;

    /** El tope de lo que acepta la web para cada texto. */
    public const LARGO_MAXIMO = 20000;

    public function __construct(private ConstanciaDeLaConsulta $constancias)
    {
    }

    /**
     * @param  string|null  $diagnosticos  el texto de diagnósticos que quedó en el editor (el automático o el editado)
     * @param  string|null  $observacion  la observación del médico
     * @param  string|null  $origen  `web`: quién escribe, para el rastro. `null` no anota rastro
     */
    public function ejecutar(
        Consulta $consulta,
        ?string $diagnosticos,
        ?string $observacion,
        ?CarbonInterface $cuando = null,
        ?string $origen = 'web',
    ): ResultadoDeSolicitud {
        $cuando = $this->ahora($cuando);

        // El texto que arma la consulta no es una edición: si el editor lo trae igual (salvo espacios), la
        // constancia sigue a los diagnósticos de la consulta y no se guarda copia.
        $editados = TextoDeLaConsulta::limpio($diagnosticos);
        if ($editados !== null && $this->sinEspacios($editados) === $this->sinEspacios($this->constancias->textoAutomatico($consulta))) {
            $editados = null;
        }

        $nuevos = [
            'observacion' => $editados,
            'observacion01' => TextoDeLaConsulta::limpio($observacion),
        ];

        return DB::transaction(function () use ($consulta, $nuevos, $cuando, $origen) {
            $fila = $this->constancias->fila($consulta);

            if ($fila === null) {
                if ($nuevos['observacion'] === null && $nuevos['observacion01'] === null) {
                    return new ResultadoDeSolicitud();
                }

                $this->crearFila(ConstanciaObs::class, 'constancia_obs', array_merge($nuevos, [
                    'reg_medico' => (string) $consulta->reg_medico,
                    'numhistoria' => (int) $consulta->numhistoria,
                    'numconsulta' => (int) $consulta->nroconsulta,
                ]), $cuando, $origen);

                return new ResultadoDeSolicitud(creado: true);
            }

            [$escritas, $bloqueadas] = $this->corregirFila($fila, 'constancia_obs', $nuevos, $cuando, $origen);

            return new ResultadoDeSolicitud(columnas: $escritas, bloqueadas: $bloqueadas);
        });
    }

    private function sinEspacios(string $texto): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $texto));
    }
}
