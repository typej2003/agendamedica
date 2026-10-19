<?php

namespace App\Actions\Consulta;

use App\Clinica\Consulta\IntervencionesDeLaConsulta;
use App\Models\Consulta;
use App\Sync\RegistroDeCambios;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * **Guardar (o editar) una intervención** de la consulta de cirugía pediátrica (2026-10-19).
 *
 * La fila es `interven_consulta` con la clave del legado `historia` + `consulta` + `codigo_inter`. Si la fila no
 * existe, se crea con el rastro `created`; si existe, se escribe **sólo lo que cambió** (`descripcion`, `detalle`)
 * con un `updated` por columna. El last-write-wins por columna es el del sync, como en el resto de la consulta.
 */
final class GuardarIntervencionDeLaConsulta
{
    public function __construct(private IntervencionesDeLaConsulta $lectura)
    {
    }

    /**
     * @param  array{codigo?:mixed, descripcion?:mixed, detalle?:mixed}  $valores
     * @return int  cuántas columnas se escribieron (1 si la fila nació)
     */
    public function ejecutar(
        Consulta $consulta,
        array $valores,
        ?CarbonInterface $cuando = null,
        string $origen = 'web',
    ): int {
        $cuando = $cuando ? Carbon::instance($cuando) : Carbon::now();
        $codigo = $this->lectura->codigo((string) ($valores['codigo'] ?? ''));

        if ($codigo === '') {
            return 0;
        }

        $descripcion = $this->normalizar($valores['descripcion'] ?? null);
        $detalle = $this->normalizar($valores['detalle'] ?? null);

        $fila = $this->lectura->fila($consulta, $codigo);

        if ($fila === null) {
            $fila = $this->lectura->filaNueva($consulta, $codigo);
            $fila->descripcion = $descripcion;
            $fila->detalle = $detalle;
            $fila->save();

            // La fila nace: el rastro es `created` (lo que el sync del escritorio entiende como alta).
            RegistroDeCambios::registrarOperacion(
                IntervencionesDeLaConsulta::TABLA,
                (int) $fila->id,
                $consulta->reg_medico,
                'created',
                null,
                null,
                $cuando,
                $origen,
            );

            return 1;
        }

        $cambios = [];
        foreach (['descripcion' => $descripcion, 'detalle' => $detalle] as $columna => $valor) {
            if ((string) ($fila->{$columna} ?? '') === (string) ($valor ?? '')) {
                continue;
            }

            if (! RegistroDeCambios::permite(IntervencionesDeLaConsulta::TABLA, (int) $fila->id, $columna, $cuando)) {
                continue;
            }

            $cambios[$columna] = $valor;
        }

        if ($cambios === []) {
            return 0;
        }

        foreach ($cambios as $columna => $valor) {
            $fila->{$columna} = $valor;
        }
        $fila->save();

        foreach ($cambios as $columna => $valor) {
            RegistroDeCambios::registrar(
                IntervencionesDeLaConsulta::TABLA,
                (int) $fila->id,
                $consulta->reg_medico,
                $columna,
                $valor,
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
