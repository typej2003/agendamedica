<?php

namespace App\Latidos;

use App\Clinica\FechaDelConsultorio;
use App\Models\Pago;
use App\Models\PagoEvento;
use App\Models\Plan;
use App\Pagos\Cobros;
use App\Services\ServicioService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * **Renovar el plan con Latidos el día del vencimiento** (fase 3, 2026-10-31).
 *
 * Crea un `Pago` aprobado al 100 % con Latidos, descuenta el libro y extiende el servicio con
 * `ServicioService::renovarAlVencer` (el período nuevo empieza el día siguiente al vencimiento).
 *
 * No se ejecuta antes del vencimiento: el comando sólo la llama cuando `vence_el <= hoy`. Si llegó tarde y
 * el servicio está en gracia, la renovación empieza hoy, como cualquier pago de un servicio vencido.
 *
 * La `referencia` del pago es determinística (`auto-<hash>`): dos corridas del comando no crean dos pagos
 * para el mismo vencimiento.
 */
final class RenovarPlanConLatidos
{
    public function __construct(
        private readonly Latidos $latidos,
        private readonly ServicioService $servicios,
        private readonly Cobros $cobros,
    ) {
    }

    /**
     * @param  array<string,mixed>  $estado  el de `RenovacionAutomatica::estado()`
     */
    public function ejecutar(string $regMedico, array $estado, ?Carbon $hoy = null): ?Pago
    {
        $regMedico = trim($regMedico);
        if ($regMedico === '' || ! ($estado['cubierta'] ?? false)) {
            return null;
        }

        $venceEl = isset($estado['vence_el']) ? Carbon::parse((string) $estado['vence_el'])->startOfDay() : null;
        $planId = (int) ($estado['plan_id'] ?? 0);
        $precioE2 = (int) ($estado['precio_usd_e2'] ?? 0);
        $latidosNecesarios = (int) ($estado['latidos_necesarios'] ?? 0);
        $meses = max(1, (int) ($estado['meses'] ?? 1));

        $hoy = ($hoy ?? FechaDelConsultorio::hoyComoFecha())->copy()->startOfDay();
        if (! $venceEl || $venceEl->gt($hoy) || $planId <= 0 || $precioE2 <= 0 || $latidosNecesarios <= 0) {
            return null;
        }

        $plan = Plan::find($planId);
        if (! $plan) {
            return null;
        }

        $referencia = 'auto-' . substr(hash('sha256', $regMedico . '|' . $venceEl->toDateString() . '|' . $plan->id), 0, 32);
        if (Pago::where('referencia', $referencia)->exists()) {
            return null;
        }

        $latido = $this->latidos->para($regMedico);

        $pago = DB::transaction(function () use (
            $regMedico, $plan, $venceEl, $precioE2, $latidosNecesarios, $meses, $referencia, $latido
        ) {
            $pago = Pago::create([
                'referencia' => $referencia,
                'reg_medico' => $regMedico,
                'beneficiario_tipo' => Pago::BENEFICIARIO_PLATAFORMA,
                'beneficiario_reg_medico' => null,
                'recaudador' => Pago::RECAUDADOR_PLATAFORMA,
                'canal' => Pago::CANAL_PLAN,
                'pagable_tipo' => Pago::PAGABLE_PLAN,
                'pagable_id' => $plan->id,
                'concepto' => 'Renovación automática · ' . $plan->nombre,
                'meses' => $meses,
                'moneda' => 'USD',
                'monto_e2' => 0,
                'monto_usd_e2' => $precioE2,
                'latidos_usados' => $latidosNecesarios,
                'descuento_usd_e2' => $precioE2,
                'monto_pasarela_usd_e2' => 0,
                'latido_id' => $latido->id,
                'tasa' => null,
                'neto_usd_e2' => $precioE2,
                'neto_ves_e2' => 0,
                'estado' => Pago::PENDIENTE,
                'expira_el' => null,
                'estado_liquidacion' => Pago::LIQUIDACION_NO_APLICA,
                'creado_por' => null,
            ]);

            $this->latidos->retener($pago, $latidosNecesarios);
            $pago->update(['estado' => Pago::APROBADO, 'pagado_el' => now()]);
            $pago->registrarEvento(PagoEvento::ORIGEN_SISTEMA, 'aprobado_automatico', [
                'latidos' => $latidosNecesarios,
                'vence_el' => $venceEl->toDateString(),
            ], Pago::PENDIENTE);
            $this->latidos->consumir($pago);

            $this->servicios->renovarAlVencer(
                $regMedico,
                $plan,
                $precioE2 / 100,
                'Renovación automática con Latidos · ' . \App\Latidos\Conversion::formatear($latidosNecesarios),
                \App\Models\RegMedicoServicio::ORIGEN_COMPRA,
                $meses,
            );

            return $pago;
        });

        $this->cobros->enviarComprobante($pago);

        return $pago;
    }
}
