<?php

namespace App\Actions\Pagos;

use App\Actions\Agenda\EliminarCita;
use App\Models\Cola;
use App\Models\Pago;
use App\Models\PagoEvento;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * **Liberar el cupo de una reserva online que no se pagó** (2026-10-30).
 *
 * Cuando el pago de una reserva vence, el turno tiene que volver a estar libre: si el paciente no pagó en
 * sus 30 minutos, otro puede tomarlo. El borrado no se inventa acá: lo hace `EliminarCita`, que ya deja el
 * rastro del `deleted` en `sync_changes` y aplica sus guardas (no atendida, sin pago registrado, no movida
 * desde el escritorio).
 *
 * Las guardas de esta acción son las de la regla de negocio, además de las de `EliminarCita`:
 *
 *  - sólo pagos de **reserva online** y de una **cita**;
 *  - la cita sigue **sin atender** y **sin `monto_pagado`**;
 *  - no hay **otro pago aprobado** de esa cita.
 *
 * Un pago de la agenda (`link`) o de un plan nunca llega hasta acá, y si la cita ya no existe o no se puede
 * borrar, devuelve `false` sin romper la conciliación.
 */
final class LiberarCupoDeReservaOnline
{
    public function __construct(private readonly EliminarCita $eliminar)
    {
    }

    /** `true` si la cita se liberó. */
    public function ejecutar(Pago $pago): bool
    {
        if ($pago->canal !== Pago::CANAL_RESERVA_ONLINE || ! $pago->esDeCita()) {
            return false;
        }

        return DB::transaction(function () use ($pago): bool {
            // Bloquear la cita evita que la borre mientras el mostrador la está cobrando o atendiendo.
            $cita = Cola::whereKey($pago->pagable_id)->lockForUpdate()->first();

            if ($cita === null) {
                return false;
            }

            if ((int) $cita->atendido === 1) {
                return false;
            }

            if ((float) ($cita->monto_pagado ?? 0) > 0) {
                return false;
            }

            $otroAprobado = Pago::where('pagable_tipo', Pago::PAGABLE_CITA)
                ->where('pagable_id', $cita->id)
                ->where('estado', Pago::APROBADO)
                ->where('id', '!=', $pago->id)
                ->exists();

            if ($otroAprobado) {
                return false;
            }

            try {
                $this->eliminar->ejecutar($cita);
            } catch (InvalidArgumentException) {
                // Una cita movida desde el escritorio, por ejemplo: se conserva y no se libera.
                return false;
            }

            $pago->registrarEvento(PagoEvento::ORIGEN_SISTEMA, 'cupo_liberado', [
                'cita_id' => $cita->id,
            ]);

            return true;
        });
    }
}
