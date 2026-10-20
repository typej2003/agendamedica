<?php

namespace App\Actions\Catalogos;

use App\Actions\Consulta\EscribeConRastro;
use App\Actions\Consulta\ReglasDelCatalogo;
use App\Models\MotivoFactura;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * **Alta de un concepto facturable** (`motivo_factura`): el catálogo del que salen los ítems de la pre-factura (F9).
 * Código asignado por el servidor (ver `ReglasDelMotivoDeFactura`), rastro `created` en `sync_changes` (`source = web`).
 */
final class CrearMotivoDeFactura
{
    use EscribeConRastro;

    /**
     * @param  array<int,string>  $registrosMedicos  los registros del médico (correlativo y no duplicar)
     *
     * @throws InvalidArgumentException si falta el nombre o el monto, un número no vale, el concepto ya existe o el catálogo está lleno
     */
    public function ejecutar(
        string $regMedico,
        array $registrosMedicos,
        ?string $descripcion,
        $monto,
        $montoConSeguro = null,
        ?CarbonInterface $cuando = null,
        ?string $origen = 'web',
    ): MotivoFactura {
        $descripcion = ReglasDelMotivoDeFactura::descripcion($descripcion);
        $monto = ReglasDelMotivoDeFactura::monto($monto);
        $montoConSeguro = ReglasDelMotivoDeFactura::montoConSeguro($montoConSeguro);
        $registros = array_values(array_unique(array_merge($registrosMedicos, [$regMedico])));
        $cuando = $this->ahora($cuando);

        return DB::transaction(function () use ($regMedico, $registros, $descripcion, $monto, $montoConSeguro, $cuando, $origen) {
            ReglasDelMotivoDeFactura::noDuplicar($registros, $descripcion);

            return $this->crearFila(MotivoFactura::class, 'motivo_factura', [
                'reg_medico' => $regMedico,
                'codigo' => ReglasDelCatalogo::siguienteCodigo(
                    MotivoFactura::whereIn('reg_medico', $registros)->pluck('codigo'),
                    'conceptos de factura',
                    ReglasDelMotivoDeFactura::DIGITOS_DEL_CODIGO,
                ),
                'descripcion' => $descripcion,
                'monto' => $monto,
                'monto_seg' => $montoConSeguro,
            ], $cuando, $origen);
        });
    }
}
