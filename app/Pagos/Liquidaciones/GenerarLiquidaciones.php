<?php

namespace App\Pagos\Liquidaciones;

use App\Models\ConfiguracionPago;
use App\Models\Liquidacion;
use App\Models\LiquidacionPago;
use App\Models\Pago;
use App\Models\TasaDeCambio;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * **La liquidación T+1**: agrupa por médico los pagos de citas que recaudó la plataforma, ya disponibles
 * (`disponible_el <= hoy`), y arma una liquidación con los totales congelados.
 *
 * Sólo entran pagos con `estado_liquidacion = pendiente` y que no estén ya en otra liquidación
 * (`liquidacion_pagos.pago_id` es único). Si el neto no llega al mínimo configurado, no se genera: se
 * acumula para la próxima.
 *
 * La liquidación nace **confirmada** (los números salen de los pagos, no de una estimación) y se marca
 * pagada cuando el administrador transfiere, con `liquidaciones:pagar`.
 */
final class GenerarLiquidaciones
{
    /** @return Collection<int,Liquidacion> */
    public function ejecutar(?string $regMedico = null, ?CarbonInterface $hoy = null): Collection
    {
        $hoy = $hoy ?? now();
        $configuracion = ConfiguracionPago::actual();
        $minimo = (int) $configuracion->liquidacion_minima_usd_e2;

        $pagos = Pago::query()
            ->where('estado', Pago::APROBADO)
            ->where('estado_liquidacion', Pago::LIQUIDACION_PENDIENTE)
            ->where('beneficiario_tipo', Pago::BENEFICIARIO_MEDICO)
            ->whereNotNull('beneficiario_reg_medico')
            ->where(function ($query) use ($hoy) {
                $query->whereNull('disponible_el')
                    ->orWhere('disponible_el', '<=', $hoy->toDateString());
            })
            ->whereDoesntHave('detalleDeLiquidacion')
            ->when(trim((string) $regMedico) !== '', fn ($query) => $query->where('beneficiario_reg_medico', trim((string) $regMedico)))
            ->orderBy('id')
            ->get();

        $generadas = collect();

        foreach ($pagos->groupBy('beneficiario_reg_medico') as $reg => $grupo) {
            $neto = (int) $grupo->sum('neto_usd_e2');

            if ($neto <= 0 || $neto < $minimo) {
                continue;
            }

            $generadas->push($this->crear((string) $reg, $grupo));
        }

        return $generadas;
    }

    /** @param  Collection<int,Pago>  $pagos */
    private function crear(string $regMedico, Collection $pagos): Liquidacion
    {
        return DB::transaction(function () use ($regMedico, $pagos) {
            $tasa = TasaDeCambio::actual();
            $fechas = $pagos
                ->map(fn (Pago $pago): ?string => ($pago->pagado_el ?? $pago->created_at)?->toDateString())
                ->filter()
                ->sort()
                ->values();

            $liquidacion = Liquidacion::create([
                'reg_medico' => $regMedico,
                'periodo_desde' => $fechas->first() ?? now()->toDateString(),
                'periodo_hasta' => $fechas->last() ?? now()->toDateString(),
                'total_bruto_usd_e2' => (int) $pagos->sum('monto_usd_e2'),
                'total_comisiones_usd_e2' => (int) $pagos->sum('comision_total_usd_e2'),
                'total_neto_usd_e2' => (int) $pagos->sum('neto_usd_e2'),
                'tasa' => $tasa?->tasa,
                'total_neto_ves_e2' => (int) $pagos->sum('neto_ves_e2'),
                'moneda' => 'VES',
                'estado' => Liquidacion::CONFIRMADA,
                'cuenta_bancaria_id' => $this->cuentaDeDestino($regMedico),
            ]);

            foreach ($pagos as $pago) {
                LiquidacionPago::create([
                    'liquidacion_id' => $liquidacion->id,
                    'pago_id' => $pago->id,
                    'bruto_usd_e2' => (int) $pago->monto_usd_e2,
                    'comisiones_usd_e2' => (int) $pago->comision_total_usd_e2,
                    'neto_usd_e2' => (int) $pago->neto_usd_e2,
                    'neto_ves_e2' => (int) $pago->neto_ves_e2,
                ]);

                $pago->update([
                    'estado_liquidacion' => Pago::LIQUIDACION_LIQUIDADO,
                    'liquidacion_id' => $liquidacion->id,
                ]);
            }

            return $liquidacion;
        });
    }

    /** La cuenta del médico que va a recibir; la predeterminada de la plataforma si no tiene. */
    private function cuentaDeDestino(string $regMedico): ?int
    {
        return \App\Models\CuentaBancaria::where('propietario', \App\Models\CuentaBancaria::MEDICO)
            ->where('reg_medico', $regMedico)
            ->orderByDesc('predeterminada')
            ->orderBy('id')
            ->value('id');
    }

    /** Marca una liquidación como pagada (la transferencia la hace el administrador por fuera). */
    public function pagar(Liquidacion $liquidacion, ?string $referencia = null): Liquidacion
    {
        if ($liquidacion->estado === Liquidacion::PAGADA) {
            throw new RuntimeException('Esa liquidación ya está pagada.');
        }

        if ($liquidacion->estado === Liquidacion::ANULADA) {
            throw new RuntimeException('Esa liquidación está anulada.');
        }

        $liquidacion->update([
            'estado' => Liquidacion::PAGADA,
            'pagada_el' => now(),
            'referencia' => $referencia !== null && trim($referencia) !== '' ? mb_substr(trim($referencia), 0, 100) : null,
        ]);

        return $liquidacion;
    }
}
