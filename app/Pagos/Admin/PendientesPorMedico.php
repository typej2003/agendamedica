<?php

namespace App\Pagos\Admin;

use App\Models\Medico;
use App\Models\Pago;
use Illuminate\Support\Collection;

/**
 * **Lo que está pendiente de cobro, agrupado por médico** (O-53, 2026-11-02): la lista que el administrador
 * necesita para saber cuánto le debe a cada consultorio y decidir si lo paga.
 *
 * Sólo mira los pagos **de citas** en `pendiente` o `en_proceso`: los planes no entran porque no se le pagan a
 * un médico (se aprueban contra la pasarela), y lo ya aprobado, rechazado o reembolsado no es deuda.
 *
 * Es una lectura sin estado: no escribe, no decide y no autoriza. La pantalla la usa para pintar los totales
 * por médico; la acción de marcar pagado vive en `AccionesDelPago`.
 *
 * El nombre del médico se resuelve por `reg_medico` y se toma **la primera ficha**, que es la misma vara que
 * usa el resto del panel (un `reg_medico` puede tener más de una).
 */
final class PendientesPorMedico
{
    /** Los dos estados que cuentan como «todavía no está la plata». */
    public const ESTADOS = [Pago::PENDIENTE, Pago::EN_PROCESO];

    /**
     * @return Collection<int,array{reg_medico:string|int,medico:?Medico,pagos:Collection<int,Pago>,cantidad:int,pendiente_usd_e2:int,pendiente_ves_e2:int}>
     */
    public function todos(): Collection
    {
        $pagos = Pago::query()
            ->where('pagable_tipo', Pago::PAGABLE_CITA)
            ->whereIn('estado', self::ESTADOS)
            ->orderByDesc('id')
            ->get();

        if ($pagos->isEmpty()) {
            return collect();
        }

        $medicos = Medico::query()
            ->whereIn('reg_medico', $pagos->pluck('reg_medico')->filter()->unique()->all())
            ->get()
            ->groupBy('reg_medico')
            ->map(fn (Collection $fichas) => $fichas->first());

        return $pagos
            ->groupBy('reg_medico')
            ->map(fn (Collection $delMedico, $regMedico) => [
                'reg_medico' => $regMedico,
                'medico' => $medicos->get($regMedico),
                'pagos' => $delMedico->values(),
                'cantidad' => $delMedico->count(),
                'pendiente_usd_e2' => (int) $delMedico->sum('monto_usd_e2'),
                // En bolívares sólo suma lo que se cobró en bolívares: mezclar monedas en un total no dice nada.
                'pendiente_ves_e2' => (int) $delMedico->where('moneda', 'VES')->sum('monto_e2'),
            ])
            ->sortByDesc('pendiente_usd_e2')
            ->values();
    }

    /** El total pendiente de todos los médicos, en centavos de USD, para el encabezado. */
    public function totalUsdE2(): int
    {
        return (int) Pago::query()
            ->where('pagable_tipo', Pago::PAGABLE_CITA)
            ->whereIn('estado', self::ESTADOS)
            ->sum('monto_usd_e2');
    }
}
