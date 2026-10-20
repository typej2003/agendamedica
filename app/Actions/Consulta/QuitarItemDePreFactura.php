<?php

namespace App\Actions\Consulta;

use App\Clinica\Consulta\PreFacturaDeLaConsulta;
use App\Models\Consulta;
use App\Sync\RegistroDeCambios;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * **Quitar un ítem de la pre-factura** de la consulta (F9): borra la fila de `his_con_pre_factura` y deja su rastro
 * `deleted` en `sync_changes`, que es lo que el sync entiende como baja. No hace nada si la fila no existe, y no toca
 * el catálogo `motivo_factura`.
 */
final class QuitarItemDePreFactura
{
    public function __construct(private PreFacturaDeLaConsulta $lectura)
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
        $codigoItem = (string) $fila->codigo;
        $fila->delete();

        RegistroDeCambios::registrarOperacion(
            PreFacturaDeLaConsulta::TABLA,
            $id,
            $consulta->reg_medico,
            'deleted',
            'codigo',
            $codigoItem,
            $cuando,
            $origen,
        );

        return true;
    }
}
