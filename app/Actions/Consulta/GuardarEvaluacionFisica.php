<?php

namespace App\Actions\Consulta;

use App\Clinica\Consulta\EvaluacionFisica;
use App\Clinica\Consulta\ExamenFisicoDeLaConsulta;
use App\Models\Consulta;
use App\Models\ExamenFisico;
use App\Sync\RegistroDeCambios;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * **Guardar la evaluación física de una consulta** (WEB-4.3, ítem 4 del panel del legado).
 *
 * La evaluación física es la única sección de la consulta cuyos datos viven **partidos en dos tablas**
 * (el mapa completo, con su fuente, está en `ExamenFisicoDeLaConsulta`):
 *
 *  - `consultas` → la antropometría y los signos (`peso`, `talla`, `fc`, `pp`, `circcefalica`,
 *    `circtoraxica`, `circabdominal`);
 *  - `examen_fisico_nuevo` → el examen por aparatos y el ginecológico (doce columnas).
 *
 * **Las dos son tablas del legado que el sync del escritorio sube**, así que cada columna escrita deja su
 * rastro en `sync_changes` (`updated`, `source = web`), como `GuardarEnfermedadActual` y las Actions de
 * la agenda: sin el rastro, una edición vieja llegaría más tarde, no tendría con qué compararse y pisaría
 * el cambio. El guard es el **last-write-wins por columna** del sync (`RegistroDeCambios::permite`).
 *
 * Tres decisiones que no son obvias y que la fuente sostiene:
 *
 *  1. **Fila nueva cuando no existe.** El escritorio crea la fila de `examen_fisico_nuevo` con todo en
 *     NULL al abrir la consulta (`w_consulta_principal.srw:5019-5029`), pero una consulta que nació en la
 *     web o en el móvil no la tiene. La Action la crea con la misma clave, en vez de perder lo cargado.
 *  2. **Nada de `UPDATE` con las 22 columnas.** Se escribe **sólo lo que cambió**: si no, cada guardado
 *     de la web dejaría veinte rastros en `sync_changes` por columnas que el médico nunca tocó, y esos
 *     rastros le ganarían a ediciones reales del escritorio y del teléfono.
 *  3. **Los resúmenes narrativos no se tocan.** `total_f` (general) y `total_g` (ginecológico) los arma el
 *     botón *Actualizar* del escritorio (`w_consulta_principal.srw:8960-9010` y `:9882-9907`) y **no** son
 *     `NULL`: en la base real hay 44.167 y 44.521 filas con ese texto. Además el resumen ginecológico
 *     incluye **colposcopia y citología** (`:9898-9902`), que son de F6. Regenerarlos desde la web
 *     borraría texto clínico del escritorio y mezclaría dos secciones, así que la web los muestra y no
 *     los escribe. Tampoco `colposcopia` ni `total_general`.
 */
final class GuardarEvaluacionFisica
{
    public function __construct(private ExamenFisicoDeLaConsulta $lectura)
    {
    }

    /**
     * @param  array<string,mixed>  $valores  las columnas del formulario (las de `EvaluacionFisica::columnas()`)
     * @param  CarbonInterface|null  $cuando  el momento de la edición; por defecto, ahora
     * @param  string  $origen  `web` o `mobile`: quién escribe, para el rastro de `sync_changes`
     */
    public function ejecutar(
        Consulta $consulta,
        array $valores,
        ?CarbonInterface $cuando = null,
        string $origen = 'web',
    ): ResultadoDeEvaluacion {
        $cuando = $cuando ? Carbon::instance($cuando) : Carbon::now();

        $fila = $this->lectura->filaParaEscribir($consulta);
        $antes = $this->lectura->de($consulta);

        $paraConsulta = [];
        $paraExamen = [];
        $bloqueadas = [];
        $sinCambios = [];

        foreach (EvaluacionFisica::columnas() as $columna => $tabla) {
            // Una columna que no vino en los valores no se toca (WEB-11.9: el examen físico y el ginecológico son dos
            // chips con su propio formulario; quien no manda una columna —el otro chip, el móvil— no la borra).
            if (! array_key_exists($columna, $valores)) {
                continue;
            }

            $nuevo = $this->normalizar($valores[$columna] ?? null);

            if ($antes->valor($columna) === $nuevo) {
                $sinCambios[] = $columna;
                continue;
            }

            if (! RegistroDeCambios::permite(
                $tabla,
                $tabla === 'consultas' ? (int) $consulta->id : (int) ($fila->id ?? 0),
                $columna,
                $cuando,
            )) {
                // Ya hay una edición igual o más nueva sobre esa columna: no se pisa (y las demás siguen).
                $bloqueadas[] = $columna;
                continue;
            }

            if ($tabla === 'consultas') {
                $paraConsulta[$columna] = $nuevo;
            } else {
                $paraExamen[$columna] = $nuevo;
            }
        }

        if ($paraConsulta !== []) {
            $this->guardarEnLaConsulta($consulta, $paraConsulta, $cuando, $origen);
        }

        if ($paraExamen !== []) {
            $this->guardarEnElExamen($consulta, $fila, $paraExamen, $cuando, $origen);
        }

        return new ResultadoDeEvaluacion(
            count($paraConsulta) + count($paraExamen),
            $bloqueadas,
            $sinCambios,
        );
    }

    /** @param array<string,?string> $valores */
    private function guardarEnLaConsulta(Consulta $consulta, array $valores, Carbon $cuando, string $origen): void
    {
        foreach ($valores as $columna => $valor) {
            // El modelo castea estas columnas a `double` y el formulario manda texto: se guarda el número,
            // para no dejar `"70"` en una columna numérica. Un valor que no sea numérico se guarda como
            // `null` y **no** como 0 —un 0 silencioso es peor que un campo vacío, porque parece un dato.
            // (El controlador ya valida que sean numéricos; esto es la red de la Action.)
            $consulta->{$columna} = is_numeric($valor) ? (float) $valor : null;
        }
        $consulta->save();

        foreach ($valores as $columna => $valor) {
            RegistroDeCambios::registrar(
                'consultas',
                $consulta->id,
                $consulta->reg_medico,
                $columna,
                // El rastro guarda lo que quedó en la columna, no lo que llegó del formulario.
                $consulta->{$columna},
                $cuando,
                $origen,
            );
        }
    }

    /** @param array<string,?string> $valores */
    private function guardarEnElExamen(Consulta $consulta, ExamenFisico $fila, array $valores, Carbon $cuando, string $origen): void
    {
        foreach ($valores as $columna => $valor) {
            $fila->{$columna} = $valor;
        }
        $fila->save();

        foreach ($valores as $columna => $valor) {
            // Acá el rastro va con el id de la fila del examen (uno por consulta), que es la fila que el
            // escritorio sube con esa clave.
            RegistroDeCambios::registrar('examen_fisico_nuevo', $fila->id, $consulta->reg_medico, $columna, $valor, $cuando, $origen);
        }
    }

    /** El `""` del formulario, el `null` de la columna y el texto con espacios de más son el mismo estado. */
    private function normalizar($valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }
}
