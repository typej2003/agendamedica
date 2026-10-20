<?php

namespace App\Actions\Consulta;

use App\Clinica\Consulta\PreFacturaDeLaConsulta;
use App\Models\Consulta;
use App\Models\MotivoFactura;
use App\Models\PreFacturaConsulta;
use App\Sync\RegistroDeCambios;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * **Cargar (o corregir) un ítem de la pre-factura** de la consulta (F9).
 *
 * La fila es `his_con_pre_factura` con la clave de negocio `numhistoria` + `nroconsulta` + `codigo`. Si no existe, nace
 * con la descripción del catálogo copiada, el `id_registro` que sigue y el rastro `created`; si existe, se escribe
 * **sólo lo que cambió** (`cantidad`, `monto`) con un `updated` por columna y el last-write-wins por columna del sync.
 *
 * El concepto llega ya resuelto contra el catálogo del médico (`PreFacturaDeLaConsulta::concepto`): esta Action no
 * acepta texto libre.
 */
final class GuardarItemDePreFactura
{
    public function __construct(private PreFacturaDeLaConsulta $lectura)
    {
    }

    /**
     * @return int  cuántas columnas se escribieron (1 si la fila nació)
     */
    public function ejecutar(
        Consulta $consulta,
        MotivoFactura $concepto,
        float $cantidad,
        ?float $monto = null,
        ?CarbonInterface $cuando = null,
        string $origen = 'web',
    ): int {
        $cuando = $cuando ? Carbon::instance($cuando) : Carbon::now();
        $codigo = $this->lectura->codigo((string) $concepto->codigo);

        if ($codigo === '' || $cantidad <= 0) {
            return 0;
        }

        $monto = round($monto ?? (float) $concepto->monto, 2);
        $cantidad = round($cantidad, 2);

        return DB::transaction(function () use ($consulta, $concepto, $codigo, $cantidad, $monto, $cuando, $origen) {
            $fila = $this->lectura->fila($consulta, $codigo);

            if ($fila === null) {
                $fila = PreFacturaConsulta::create([
                    'reg_medico'  => $consulta->reg_medico,
                    'id_registro' => $this->lectura->siguienteRegistro($consulta->reg_medico),
                    'numhistoria' => (int) $consulta->numhistoria,
                    'nroconsulta' => (int) $consulta->nroconsulta,
                    'codigo'      => $codigo,
                    'descripcion' => mb_substr(trim((string) $concepto->descripcion), 0, PreFacturaDeLaConsulta::LARGO_DESCRIPCION),
                    'cantidad'    => $cantidad,
                    'monto'       => $monto,
                ]);

                RegistroDeCambios::registrarOperacion(
                    PreFacturaDeLaConsulta::TABLA,
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
            foreach (['cantidad' => $cantidad, 'monto' => $monto] as $columna => $valor) {
                if (round((float) ($fila->{$columna} ?? 0), 2) === $valor && $fila->{$columna} !== null) {
                    continue;
                }

                if (! RegistroDeCambios::permite(PreFacturaDeLaConsulta::TABLA, (int) $fila->id, $columna, $cuando)) {
                    continue;
                }

                $cambios[$columna] = $valor;
            }

            if ($cambios === []) {
                return 0;
            }

            $fila->fill($cambios)->save();

            foreach ($cambios as $columna => $valor) {
                RegistroDeCambios::registrar(
                    PreFacturaDeLaConsulta::TABLA,
                    (int) $fila->id,
                    $consulta->reg_medico,
                    $columna,
                    $valor,
                    $cuando,
                    $origen,
                );
            }

            return count($cambios);
        });
    }
}
