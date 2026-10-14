<?php

namespace App\Actions\Consulta;

use App\Clinica\Consulta\ColposcopiaDeLaConsulta;
use App\Clinica\Consulta\ExamenFisicoDeLaConsulta;
use App\Clinica\Consulta\TextoDeLaConsulta;
use App\Models\Consulta;
use App\Sync\RegistroDeCambios;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * **Guardar la colposcopía de una consulta** (WEB-6.5): el texto del informe del examen colposcópico.
 *
 * El texto del escritorio es **uno** y vive en **dos columnas de dos tablas** (el mapa del dato, con su
 * fuente, está en `App\Clinica\Consulta\ColposcopiaDeLaConsulta`):
 *
 *  - `consultas.observaciones` — la **canónica**, la que imprime el informe;
 *  - `examen_fisico_nuevo.colposcopia` — la **copia** que el escritorio deja para su resumen ginecológico.
 *
 * Las dos son tablas del legado que el sync sube, así que cada columna escrita deja su rastro en
 * `sync_changes` (`updated`, `source = web`) con el id de **su** fila —el de la consulta para `consultas`, el
 * de la fila del examen para `examen_fisico_nuevo`, la misma convención que `GuardarEvaluacionFisica`— y el
 * guard es el **last-write-wins por columna** del sync (`RegistroDeCambios::permite`).
 *
 * Tres reglas, las mismas del resto de las secciones de la consulta:
 *
 *  1. **Sólo lo que cambió**: si el texto es el mismo, no hay `UPDATE` ni rastro. Y si una columna ya tiene
 *     el texto (una consulta guardada a medias) se escribe igual la que falta.
 *  2. **El `""` del formulario es el mismo estado que el `null` de la columna** (y el literal `none` del
 *     legado): se guarda `null`.
 *  3. **No se pisa una edición más nueva**: la columna bloqueada se devuelve en `bloqueadas` y el
 *     controlador lo dice en pantalla en vez de mostrar un "Guardado" falso.
 */
final class GuardarColposcopiaDeLaConsulta
{
    public function __construct(private ExamenFisicoDeLaConsulta $examen)
    {
    }

    /**
     * @param  string|null  $texto  el texto del formulario
     * @param  CarbonInterface|null  $cuando  el momento de la edición; por defecto, ahora
     * @param  string  $origen  `web` o `mobile`: quién escribe, para el rastro de `sync_changes`
     */
    public function ejecutar(
        Consulta $consulta,
        ?string $texto,
        ?CarbonInterface $cuando = null,
        string $origen = 'web',
    ): ResultadoDeSolicitud {
        $cuando = $cuando ? Carbon::instance($cuando) : Carbon::now();
        $texto = TextoDeLaConsulta::limpio($texto);

        $escritas = [];
        $bloqueadas = [];

        // 1. La canónica (`consultas.observaciones`), que es la que imprime el informe.
        if ($this->comparable($consulta->observaciones) !== $texto) {
            if (RegistroDeCambios::permite('consultas', (int) $consulta->id, ColposcopiaDeLaConsulta::COLUMNA, $cuando)) {
                $consulta->observaciones = $texto;
                $consulta->save();

                RegistroDeCambios::registrar(
                    'consultas', (int) $consulta->id, $consulta->reg_medico,
                    ColposcopiaDeLaConsulta::COLUMNA, $texto, $cuando, $origen,
                );

                $escritas[] = 'consultas.' . ColposcopiaDeLaConsulta::COLUMNA;
            } else {
                $bloqueadas[] = 'consultas.' . ColposcopiaDeLaConsulta::COLUMNA;
            }
        }

        // 2. La copia en el examen (`examen_fisico_nuevo.colposcopia`). La fila se crea si no existe, como
        //    la crea el escritorio al abrir la consulta.
        $fila = $this->examen->filaParaEscribir($consulta);

        if ($this->comparable($fila->getAttribute(ColposcopiaDeLaConsulta::COLUMNA_ESPEJO)) !== $texto) {
            if (RegistroDeCambios::permite('examen_fisico_nuevo', (int) ($fila->id ?? 0), ColposcopiaDeLaConsulta::COLUMNA_ESPEJO, $cuando)) {
                $fila->setAttribute(ColposcopiaDeLaConsulta::COLUMNA_ESPEJO, $texto);
                $fila->save();

                RegistroDeCambios::registrar(
                    'examen_fisico_nuevo', (int) $fila->id, $consulta->reg_medico,
                    ColposcopiaDeLaConsulta::COLUMNA_ESPEJO, $texto, $cuando, $origen,
                );

                $escritas[] = 'examen_fisico_nuevo.' . ColposcopiaDeLaConsulta::COLUMNA_ESPEJO;
            } else {
                $bloqueadas[] = 'examen_fisico_nuevo.' . ColposcopiaDeLaConsulta::COLUMNA_ESPEJO;
            }
        }

        return new ResultadoDeSolicitud(columnas: $escritas, bloqueadas: $bloqueadas);
    }

    /** Un valor como texto comparable: `null`, el `""` del formulario y el `none` del legado son "sin texto". */
    private function comparable($valor): ?string
    {
        return TextoDeLaConsulta::limpio($valor === null ? null : (string) $valor);
    }
}
