<?php

namespace App\Actions\Consulta;

use App\Clinica\Consulta\EcoRenal;
use App\Models\Consulta;
use App\Sync\RegistroDeCambios;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * **Guardar el eco renal** de una consulta (nefrología pediátrica, 2026-10-19).
 *
 * Una fila por consulta en `ultra_renal` (clave `historia` + `consulta`), con las mismas reglas de siempre: **sólo
 * lo que cambió** por columna, **rastro por columna** en `sync_changes` (`source = web`), last-write-wins del sync y
 * **sin filas vacías**.
 */
final class GuardarEcoRenal
{
    public function __construct(private EcoRenal $lectura)
    {
    }

    /**
     * @param  array<string,mixed>  $valores  los campos (claves de `EcoRenal::campos()`)
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

        foreach (EcoRenal::campos() as $clave => $campo) {
            $nuevo = $this->normalizar($valores[$clave] ?? null);

            if (($antes[$clave] ?? null) === $nuevo) {
                continue;
            }

            if (! RegistroDeCambios::permite(EcoRenal::TABLA, (int) ($fila->id ?? 0), $campo['columna'], $cuando)) {
                continue;
            }

            $cambios[$campo['columna']] = $nuevo;
        }

        if ($cambios === []) {
            return 0;
        }

        $fila = $fila ?? $this->lectura->filaParaEscribir($consulta);

        foreach ($cambios as $columna => $valor) {
            $fila->{$columna} = $valor;
        }
        $fila->save();

        foreach ($cambios as $columna => $valor) {
            RegistroDeCambios::registrar(
                EcoRenal::TABLA,
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
