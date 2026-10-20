<?php

namespace App\Actions\Catalogos;

use App\Actions\Consulta\EscribeConRastro;
use App\Models\MotivoFactura;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * **Editar un concepto facturable**: nombre, monto y monto con seguro; el código no se edita. Sólo lo que cambió, con el
 * last-write-wins del sync y su `updated` por columna (`source = web`).
 *
 * Los ítems de pre-facturas ya cargadas **no cambian**: copian la descripción y llevan su propio monto.
 */
final class EditarMotivoDeFactura
{
    use EscribeConRastro;

    /**
     * @param  array<int,string>  $registros  los registros del médico
     *
     * @throws InvalidArgumentException como el alta
     */
    public function ejecutar(
        MotivoFactura $fila,
        array $registros,
        ?string $descripcion,
        $monto,
        $montoConSeguro = null,
        ?CarbonInterface $cuando = null,
        ?string $origen = 'web',
    ): ResultadoDeEdicion {
        $descripcion = ReglasDelMotivoDeFactura::descripcion($descripcion);
        $monto = ReglasDelMotivoDeFactura::monto($monto);
        $montoConSeguro = ReglasDelMotivoDeFactura::montoConSeguro($montoConSeguro);
        $cuando = $this->ahora($cuando);

        return DB::transaction(function () use ($fila, $registros, $descripcion, $monto, $montoConSeguro, $cuando, $origen) {
            $bloqueada = MotivoFactura::whereKey($fila->id)->lockForUpdate()->firstOrFail();
            $todos = array_values(array_unique(array_merge($registros, [(string) $bloqueada->reg_medico])));

            // Un duplicado histórico se puede seguir editando mientras no se le ponga el nombre de otro.
            if (ReglasDelMotivoDeFactura::clave((string) $bloqueada->descripcion) !== ReglasDelMotivoDeFactura::clave($descripcion)) {
                ReglasDelMotivoDeFactura::noDuplicar($todos, $descripcion, (int) $bloqueada->id);
            }

            [$escritas, $bloqueadas] = $this->corregirFila($bloqueada, 'motivo_factura', [
                'descripcion' => $descripcion,
                'monto' => $monto,
                'monto_seg' => $montoConSeguro,
            ], $cuando, $origen);

            return new ResultadoDeEdicion($bloqueada, $escritas, $bloqueadas);
        });
    }
}
