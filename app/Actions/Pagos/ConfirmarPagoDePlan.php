<?php

namespace App\Actions\Pagos;

use App\Models\Pago;
use App\Models\Plan;
use App\Models\RegMedicoServicio;
use App\Services\ServicioService;
use RuntimeException;

/**
 * **Aplicar un pago de plan aprobado**: renueva el servicio del médico con la regla que ya existe
 * (`ServicioService::renovar`), no con una nueva.
 *
 * El monto es el que se congeló en el pago (`monto_usd_e2`), no el precio actual del plan: si el
 * administrador cambió el precio mientras el médico pagaba, se respeta lo que se le cobró. El período
 * nuevo arranca donde termina el actual si todavía no venció (lo decide `ServicioService`).
 *
 * Se llama **dentro** de la transacción que aprueba el pago, así que el pago y el servicio se escriben
 * juntos o no se escribe ninguno.
 */
final class ConfirmarPagoDePlan
{
    public function __construct(private readonly ServicioService $servicios)
    {
    }

    public function ejecutar(Pago $pago): ?RegMedicoServicio
    {
        if (! $pago->esDePlan()) {
            throw new RuntimeException('El pago no es de un plan.');
        }
        if (! $pago->estaAprobado()) {
            throw new RuntimeException('El pago tiene que estar aprobado para renovar el plan.');
        }

        $plan = Plan::find($pago->pagable_id);
        if (! $plan) {
            throw new RuntimeException('El plan del pago ya no existe.');
        }

        $regMedico = trim((string) $pago->reg_medico);
        if ($regMedico === '') {
            throw new RuntimeException('El pago no tiene consultorio.');
        }

        $montoUsd = (float) ($pago->monto_usd_e2 / 100);
        $meses = (int) ($pago->meses ?: $plan->meses());
        $nota = 'Pago #' . $pago->id . ' · ' . $pago->montoTexto();
        if ($pago->tasa !== null) {
            $nota .= ' (tasa ' . number_format((float) $pago->tasa, 4, ',', '.') . ')';
        }

        return $this->servicios->renovar(
            $regMedico,
            $plan,
            $montoUsd,
            $nota,
            RegMedicoServicio::ORIGEN_COMPRA,
            $meses,
        );
    }
}
