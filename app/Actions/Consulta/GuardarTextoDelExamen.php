<?php

namespace App\Actions\Consulta;

use App\Clinica\Consulta\ExamenFisicoDeLaConsulta;
use App\Clinica\Consulta\TextoDeLaConsulta;
use App\Clinica\Consulta\TextosDelExamen;
use App\Models\Consulta;
use App\Sync\RegistroDeCambios;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * **Guardar el texto de una solapa del examen** (WEB-6.5): la colposcopía, la citología, la histeroscopía o el
 * resultado de exámenes.
 *
 * ## Dónde escribe (y por qué una sola vez)
 *
 * El texto vive en **una columna de `consultas`** (`observaciones`, `faringe`, `cuello` u `oido`, ver
 * `TextosDelExamen`), que es la fila de la consulta: la misma que el escritorio actualiza entera al guardar
 * (`w_consulta_principal.srw:2816-2840`). La **colposcopía** se escribe además en su **copia**
 * `examen_fisico_nuevo.colposcopia` —el `UPDATE` que el escritorio dejó vivo—, así que esa solapa tiene dos
 * columnas y tres no. Esa es toda la diferencia entre las cuatro: la clase no repite nada.
 *
 * ## Las tres reglas de siempre (R3)
 *
 *  - **Sólo lo que cambió**: se compara contra el valor comparable (`null`, `""` y el `none` del legado son
 *    "sin texto") y no se escribe una columna que ya tenía ese texto.
 *  - **Rastro por columna, en su tabla**: una fila de `sync_changes` con `source = web` (o el origen que
 *    reciba) por cada columna escrita, con el id de la fila que corresponde.
 *  - **Last-write-wins**: `RegistroDeCambios::permite` decide; una columna que otro equipo escribió más tarde
 *    queda **bloqueada** y se informa, que es lo que `ResultadoDeSolicitud` distingue del "no había cambios".
 *
 * El sync no cambia: el escritorio sigue subiendo su `UPDATE` por su camino genérico y la web deja su rastro.
 */
final class GuardarTextoDelExamen
{
    public function __construct(private ExamenFisicoDeLaConsulta $examen)
    {
    }

    /**
     * @param  string  $clave  una clave de `TextosDelExamen`
     * @param  string|null  $texto  el texto del formulario
     * @param  CarbonInterface|null  $cuando  el momento de la edición; por defecto, ahora
     * @param  string  $origen  `web` o `mobile`: quién escribe, para el rastro de `sync_changes`
     */
    public function ejecutar(
        Consulta $consulta,
        string $clave,
        ?string $texto,
        ?CarbonInterface $cuando = null,
        string $origen = 'web',
    ): ResultadoDeSolicitud {
        $columna = TextosDelExamen::columna($clave);

        if ($columna === null) {
            return new ResultadoDeSolicitud(columnas: [], bloqueadas: []);
        }

        $cuando = $cuando ? Carbon::instance($cuando) : Carbon::now();
        $texto = TextoDeLaConsulta::limpio($texto);

        $escritas = [];
        $bloqueadas = [];

        // 1. La canónica (la columna de `consultas`), que es la que imprime la hoja.
        if ($this->comparable($consulta->getAttribute($columna)) !== $texto) {
            if (RegistroDeCambios::permite('consultas', (int) $consulta->id, $columna, $cuando)) {
                $consulta->setAttribute($columna, $texto);
                $consulta->save();

                RegistroDeCambios::registrar(
                    'consultas', (int) $consulta->id, $consulta->reg_medico,
                    $columna, $texto, $cuando, $origen,
                );

                $escritas[] = 'consultas.' . $columna;
            } else {
                $bloqueadas[] = 'consultas.' . $columna;
            }
        }

        // 2. La copia, si la solapa tiene (hoy sólo la colposcopía). La fila se crea si no existe, como la
        //    crea el escritorio al abrir la consulta.
        $espejo = TextosDelExamen::columnaEspejo($clave);

        if ($espejo !== null) {
            $fila = $this->examen->filaParaEscribir($consulta);

            if ($this->comparable($fila->getAttribute($espejo)) !== $texto) {
                if (RegistroDeCambios::permite('examen_fisico_nuevo', (int) ($fila->id ?? 0), $espejo, $cuando)) {
                    $fila->setAttribute($espejo, $texto);
                    $fila->save();

                    RegistroDeCambios::registrar(
                        'examen_fisico_nuevo', (int) $fila->id, $consulta->reg_medico,
                        $espejo, $texto, $cuando, $origen,
                    );

                    $escritas[] = 'examen_fisico_nuevo.' . $espejo;
                } else {
                    $bloqueadas[] = 'examen_fisico_nuevo.' . $espejo;
                }
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
