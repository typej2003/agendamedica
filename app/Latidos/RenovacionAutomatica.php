<?php

namespace App\Latidos;

use App\Models\Latido;
use App\Models\Plan;
use App\Services\CupoDelPlan;
use App\Services\ServicioService;

/**
 * **El estado de la renovación automática con Latidos** (fase 3, 2026-10-31).
 *
 * Responde tres preguntas sin escribir nada más que la fila de Latidos si no existía:
 *  - ¿la preferencia está encendida?
 *  - ¿los Latidos alcanzan para pagar el **100 %** del plan en el próximo vencimiento?
 *  - ¿hay que mostrar el recordatorio de “por vencer”?
 *
 * Si el médico no eligió un plan para la renovación (`latidos.renovacion_plan_id`), se usa el plan vigente:
 * así la preferencia encendida por defecto sirve desde el primer mes. Si tampoco hay plan vigente, no se
 * renueva.
 */
final class RenovacionAutomatica
{
    public function __construct(
        private readonly ServicioService $servicios,
        private readonly CupoDelPlan $cupos,
        private readonly Latidos $latidos,
    ) {
    }

    /**
     * @return array{
     *     activa:bool, cubierta:bool, aviso_por_vencer:bool, motivo:string,
     *     vence_el:?string, plan:?string, plan_id:?int, meses:int,
     *     precio_usd_e2:int, latidos_necesarios:int, latidos_disponibles:int
     * }
     */
    public function estado(string $regMedico): array
    {
        $regMedico = trim($regMedico);
        if ($regMedico === '') {
            return $this->vacio('sin_consultorio');
        }

        $latido = $this->latidos->para($regMedico);
        $actual = $this->servicios->actual($regMedico);
        $plan = $this->plan($latido, $actual);
        $meses = $this->meses($latido, $actual, $plan);
        $disponibles = $this->latidos->disponiblesParaUsar($regMedico);

        $precio = 0;
        $necesarios = 0;
        $cubierta = false;
        $motivo = 'cubierta';

        if (! $latido->renovacion_automatica) {
            $motivo = 'apagada';
        } elseif (! $actual) {
            $motivo = 'sin_servicio';
        } elseif (! $plan) {
            $motivo = 'sin_plan';
        } elseif (! $plan->activo) {
            $motivo = 'plan_inactivo';
        } elseif ($meses >= 12 && ! $plan->ofreceAnual()) {
            $motivo = 'frecuencia_no_disponible';
        } elseif ($meses < 12 && ! $plan->ofreceMensual()) {
            $motivo = 'frecuencia_no_disponible';
        } elseif ($this->cupos->motivoNoCabe($plan, $regMedico) !== null) {
            $motivo = 'plan_no_cubre';
        } else {
            $frecuencia = $meses >= 12 ? Plan::ANUAL : Plan::MENSUAL;
            $conteo = $this->cupos->conteo($regMedico);
            $precio = $this->cupos->precioUsdE2($plan, $conteo, $frecuencia);

            if ($precio <= 0) {
                $motivo = 'plan_gratuito';
            } else {
                $necesarios = Conversion::desdeUsdE2($precio);
                $cubierta = $disponibles >= $necesarios;
                $motivo = $cubierta ? 'cubierta' : 'latidos_insuficientes';
            }
        }

        return [
            'activa' => (bool) $latido->renovacion_automatica,
            'cubierta' => $cubierta,
            'aviso_por_vencer' => ! $cubierta,
            'motivo' => $motivo,
            'vence_el' => $actual?->vence_el?->toDateString(),
            'plan' => $plan?->nombre,
            'plan_id' => $plan?->id,
            'meses' => $meses,
            'precio_usd_e2' => $precio,
            'latidos_necesarios' => $necesarios,
            'latidos_disponibles' => $disponibles,
        ];
    }

    /** @return array{activa:bool,cubierta:bool,aviso_por_vencer:bool,motivo:string,vence_el:null,plan:null,plan_id:null,meses:int,precio_usd_e2:int,latidos_necesarios:int,latidos_disponibles:int} */
    private function vacio(string $motivo): array
    {
        return [
            'activa' => false, 'cubierta' => false, 'aviso_por_vencer' => true, 'motivo' => $motivo,
            'vence_el' => null, 'plan' => null, 'plan_id' => null, 'meses' => 1,
            'precio_usd_e2' => 0, 'latidos_necesarios' => 0, 'latidos_disponibles' => 0,
        ];
    }

    private function plan(Latido $latido, $actual): ?Plan
    {
        if ($latido->renovacion_plan_id) {
            return Plan::find($latido->renovacion_plan_id);
        }
        if ($actual?->plan_id) {
            return Plan::find($actual->plan_id);
        }

        return $actual?->plan;
    }

    private function meses(Latido $latido, $actual, ?Plan $plan): int
    {
        if ((int) $latido->renovacion_meses >= 1) {
            return (int) $latido->renovacion_meses;
        }

        if ($actual) {
            $meses = (int) round($actual->inicia_el->diffInMonths($actual->vence_el));
            if ($meses >= 1) {
                return $meses;
            }
        }

        return max(1, (int) ($plan?->meses() ?? 1));
    }
}
