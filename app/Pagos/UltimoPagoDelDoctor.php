<?php

namespace App\Pagos;

use App\Models\Pago;

/**
 * **Los datos del pagador del último pago de plan aprobado** del médico, para no volver a escribirlos en el
 * checkout siguiente.
 *
 * Se buscan por `creado_por` y no por `reg_medico`: son los datos bancarios de la persona que paga, no los
 * del consultorio. Así un médico que comparte un centro no ve los datos de otro, y el mismo médico los
 * conserva aunque pague el plan de otro registro.
 *
 * Sólo se recuerda lo que se guardó en un pago **aprobado** (`datos_pagador`): un intento fallido o
 * abandonado no pisa los datos buenos.
 */
final class UltimoPagoDelDoctor
{
    /**
     * @return array<string,string>
     */
    public function datosParaPagar(?int $usuarioId): array
    {
        if (! $usuarioId) {
            return [];
        }

        $pago = Pago::query()
            ->where('creado_por', $usuarioId)
            ->where('canal', Pago::CANAL_PLAN)
            ->where('estado', Pago::APROBADO)
            ->whereNotNull('datos_pagador')
            ->orderByDesc('pagado_el')
            ->orderByDesc('id')
            ->first();

        return $pago?->datos_pagador ?? [];
    }
}
