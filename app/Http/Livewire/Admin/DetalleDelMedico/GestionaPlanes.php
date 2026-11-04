<?php

namespace App\Http\Livewire\Admin\DetalleDelMedico;

use App\Models\Plan;
use App\Models\RegMedicoServicio;
use App\Pagos\Centavos;
use App\Services\CupoDelPlan;
use App\Services\ServicioService;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * **Plan del médico**, administrado desde su detalle: renovar, reemplazar, quitar y ver el historial.
 *
 * La regla está en `ServicioService` (la misma que usa la pantalla «Planes y servicios»); acá sólo se arma el
 * formulario y se avisa. El cupo del equipo se comprueba con `CupoDelPlan`, igual que allá.
 */
trait GestionaPlanes
{
    /** Formulario abierto: `renovar` | `reemplazar` | `quitar` | null */
    public $planAccion = null;

    public $planElegido = null;
    public $meses = 1;
    public $monto = 0;
    public $nota = '';
    public $motivo = '';

    public function abrirRenovarPlan(ServicioService $servicios): void
    {
        $this->resetErrorBag();
        $this->planAccion = 'renovar';
        $this->nota = '';
        $this->motivo = '';
        $this->aplicarPlan($this->planSugerido($servicios), $this->medico);
    }

    public function abrirReemplazarPlan(ServicioService $servicios): void
    {
        $actual = $servicios->actual((string) $this->medico->reg_medico);

        if (! $actual) {
            session()->flash('error', 'Este médico no tiene servicio que reemplazar: usa Renovar para asignarle un plan.');

            return;
        }

        $this->resetErrorBag();
        $this->planAccion = 'reemplazar';
        $this->nota = '';
        $this->motivo = '';
        $this->aplicarPlan($this->planSugerido($servicios), $this->medico);
    }

    public function abrirQuitarPlan(ServicioService $servicios): void
    {
        $this->resetErrorBag();

        if (! $servicios->actual((string) $this->medico->reg_medico)) {
            session()->flash('error', 'Este médico ya no tiene servicio.');

            return;
        }

        $this->motivo = '';
        $this->planAccion = 'quitar';
    }

    public function cerrarPlan(): void
    {
        $this->planAccion = null;
        $this->planElegido = null;
        $this->meses = 1;
        $this->monto = 0;
        $this->nota = '';
        $this->motivo = '';
        $this->resetErrorBag();
    }

    public function updatedPlanElegido($valor): void
    {
        $this->aplicarPlan(Plan::where('activo', true)->find($valor), $this->medico);
    }

    public function renovarPlan(ServicioService $servicios): void
    {
        $this->validate([
            'planElegido' => ['required', Rule::exists('planes', 'id')->where('activo', true)],
            'meses' => 'required|integer|min:1|max:60',
            'monto' => 'required|numeric|min:0|max:99999',
            'nota' => 'nullable|string|max:255',
        ], [], ['planElegido' => 'plan', 'meses' => 'meses', 'monto' => 'monto']);

        $plan = Plan::findOrFail($this->planElegido);

        if (! $this->exigirQueCubra($plan)) {
            return;
        }

        $monto = (float) $this->monto;
        $nuevo = $servicios->renovar(
            (string) $this->medico->reg_medico,
            $plan,
            $monto,
            trim((string) $this->nota) ?: null,
            $monto > 0 ? RegMedicoServicio::ORIGEN_COMPRA : RegMedicoServicio::ORIGEN_MANUAL,
            (int) $this->meses,
        );

        session()->flash('message', trim($this->medico->name . ' ' . $this->medico->lastname) . ': servicio hasta el '
            . $nuevo->vence_el->format(\App\Support\Regionalizacion::formatoActual()) . '.');
        $this->cerrarPlan();
    }

    public function reemplazarPlan(ServicioService $servicios): void
    {
        $this->validate([
            'planElegido' => ['required', Rule::exists('planes', 'id')->where('activo', true)],
            'meses' => 'required|integer|min:1|max:60',
            'monto' => 'required|numeric|min:0|max:99999',
            'motivo' => 'required|string|min:3|max:150',
        ], [], ['planElegido' => 'plan', 'meses' => 'meses', 'monto' => 'monto', 'motivo' => 'motivo']);

        if (! $servicios->actual((string) $this->medico->reg_medico)) {
            session()->flash('error', 'Este médico no tiene servicio que reemplazar: usa Renovar para asignarle un plan.');
            $this->cerrarPlan();

            return;
        }

        $plan = Plan::findOrFail($this->planElegido);

        if (! $this->exigirQueCubra($plan)) {
            return;
        }

        $nuevo = $servicios->reemplazar(
            (string) $this->medico->reg_medico,
            $plan,
            (float) $this->monto,
            $this->motivo,
            (int) $this->meses,
            auth()->user()->name,
        );

        session()->flash('message', trim($this->medico->name . ' ' . $this->medico->lastname) . ': plan reemplazado, servicio hasta el '
            . $nuevo->vence_el->format(\App\Support\Regionalizacion::formatoActual()) . '.');
        $this->cerrarPlan();
    }

    public function quitarPlanDelMedico(ServicioService $servicios): void
    {
        $this->validate(['motivo' => 'required|string|min:3|max:150'], [], ['motivo' => 'motivo']);

        $cancelados = $servicios->cancelarActivos((string) $this->medico->reg_medico, $this->motivo, auth()->user()->name);

        session()->flash('message', $cancelados === 0
            ? trim($this->medico->name . ' ' . $this->medico->lastname) . ' ya no tenía servicio.'
            : trim($this->medico->name . ' ' . $this->medico->lastname) . ': plan quitado, ya no sincroniza. Para devolvérselo, asígnale un plan con Renovar.');
        $this->cerrarPlan();
    }

    /** Cancela un servicio del historial (sólo uno de este médico). */
    public function cancelarServicioDelMedico(int $servicioId, ServicioService $servicios): void
    {
        $servicio = RegMedicoServicio::findOrFail($servicioId);

        abort_unless((string) $servicio->reg_medico === (string) $this->medico->reg_medico, 403);

        $servicios->cancelar($servicio);
        session()->flash('message', 'Servicio cancelado: ya no cuenta para el vencimiento.');
    }

    private function planSugerido(ServicioService $servicios): ?Plan
    {
        $actual = $servicios->actual((string) $this->medico->reg_medico);

        return ($actual?->plan_id ? Plan::where('activo', true)->find($actual->plan_id) : null)
            ?? Plan::where('activo', true)->where('visible', true)->orderBy('orden')->first()
            ?? Plan::porDefecto();
    }

    private function aplicarPlan(?Plan $plan, ?\App\Models\Medico $medico = null): void
    {
        $this->planElegido = $plan?->id;

        if ($plan === null) {
            $this->meses = 1;
            $this->monto = 0;

            return;
        }

        $frecuencia = $plan->frecuenciaPorDefecto();
        $this->meses = $plan->meses($frecuencia);

        if ($plan->cobraPorAsiento() && $medico !== null) {
            $cupos = app(CupoDelPlan::class);
            $this->monto = Centavos::aDecimal($cupos->precioUsdE2(
                $plan,
                $cupos->conteo((string) $medico->reg_medico),
                $frecuencia,
            ));

            return;
        }

        $this->monto = $frecuencia === Plan::ANUAL
            ? (float) ($plan->precio_anual_usd ?? ($plan->frecuencia === Plan::ANUAL ? $plan->precio_usd : 0))
            : (float) $plan->precio_usd;
    }

    private function exigirQueCubra(Plan $plan): bool
    {
        try {
            app(CupoDelPlan::class)->exigirQueCubra($plan, (string) $this->medico->reg_medico);

            return true;
        } catch (ValidationException $e) {
            $this->addError('planElegido', $e->errors()['plan'][0] ?? $e->getMessage());

            return false;
        }
    }
}
