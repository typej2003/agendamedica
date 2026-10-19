<?php

namespace App\Actions\Consulta;

use App\Clinica\Consulta\IntervencionesDeLaConsulta;
use App\Models\Consulta;
use App\Sync\RegistroDeCambios;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * **Quitar una intervención** de la consulta de cirugía pediátrica (2026-10-19).
 *
 * Borra la fila de `interven_consulta` y deja su rastro `deleted` en `sync_changes` con `source = web`, que es lo
 * que el sync del escritorio entiende como baja. No hace nada si la fila no existe.
 */
final class QuitarIntervencionDeLaConsulta
{
    public function __construct(private IntervencionesDeLaConsulta $lectura)
    {
    }

    public function ejecutar(
        Consulta $consulta,
        string $codigo,
        ?CarbonInterface $cuando = null,
        string $origen = 'web',
    ): bool {
        $cuando = $cuando ? Carbon::instance($cuando) : Carbon::now();
        $fila = $this->lectura->fila($consulta, $codigo);

        if ($fila === null) {
            return false;
        }

        $id = (int) $fila->id;
        $codigoInter = (string) $fila->codigo_inter;
        $fila->delete();

        RegistroDeCambios::registrarOperacion(
            IntervencionesDeLaConsulta::TABLA,
            $id,
            $consulta->reg_medico,
            'deleted',
            'codigo_inter',
            $codigoInter,
            $cuando,
            $origen,
        );

        return true;
    }
}
