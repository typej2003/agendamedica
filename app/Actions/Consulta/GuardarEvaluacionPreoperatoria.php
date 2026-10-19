<?php

namespace App\Actions\Consulta;

use App\Clinica\Consulta\EvaluacionPreoperatoria;
use App\Models\Consulta;
use App\Models\PreAnestesico;
use App\Sync\RegistroDeCambios;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * **Guardar la evaluación pre-operatoria** de una consulta (cirugía pediátrica, 2026-10-19).
 *
 * Una fila por consulta en `pre_aneste` (clave `historia` + `consulta`), y las mismas reglas de siempre:
 * **sólo lo que cambió** (por columna), **rastro por columna** en `sync_changes` (`source = web`), last-write-wins
 * del sync y **sin filas vacías**. `emergencia` es `char(1)`: se guarda el primer carácter.
 */
final class GuardarEvaluacionPreoperatoria
{
    public function __construct(private EvaluacionPreoperatoria $lectura)
    {
    }

    /**
     * @param  array<string,mixed>  $valores  los campos (claves de `EvaluacionPreoperatoria::claves()`)
     * @return int  cuántas columnas se escribieron
     */
    public function ejecutar(
        Consulta $consulta,
        array $valores,
        ?CarbonInterface $cuando = null,
        string $origen = 'web',
    ): int {
        $cuando = $cuando ? Carbon::instance($cuando) : Carbon::now();
        $fila = $this->lectura->fila($consulta);
        $antes = $this->lectura->de($consulta);
        $cambios = [];

        foreach (EvaluacionPreoperatoria::campos() as $clave => $campo) {
            $nuevo = $this->normalizar($valores[$clave] ?? null);

            if (($antes[$clave] ?? null) === $nuevo) {
                continue;
            }

            if (! RegistroDeCambios::permite(EvaluacionPreoperatoria::TABLA, (int) ($fila->id ?? 0), $campo['columna'], $cuando)) {
                continue;
            }

            $cambios[$campo['columna']] = $nuevo;
        }

        if ($cambios === []) {
            return 0;
        }

        /** @var PreAnestesico $fila */
        $fila = $fila ?? $this->lectura->filaParaEscribir($consulta);

        foreach ($cambios as $columna => $valor) {
            // `tipo` y `emergencia` son `char(1)`: se guarda el primer carácter, no el texto.
            if (in_array($columna, ['tipo', 'emergencia'], true) && $valor !== null) {
                $valor = mb_substr($valor, 0, 1);
            }

            $fila->{$columna} = $valor;
        }
        $fila->save();

        foreach ($cambios as $columna => $valor) {
            RegistroDeCambios::registrar(
                EvaluacionPreoperatoria::TABLA,
                (int) $fila->id,
                $consulta->reg_medico,
                $columna,
                $fila->{$columna},
                $cuando,
                $origen,
            );
        }

        return count($cambios);
    }

    private function normalizar($valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }
}
