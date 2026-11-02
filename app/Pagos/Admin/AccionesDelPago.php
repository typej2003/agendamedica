<?php

namespace App\Pagos\Admin;

use App\Actions\Agenda\CobrarCita;
use App\Models\Pago;
use App\Models\PagoEvento;
use App\Models\User;
use App\Pagos\Centavos;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * **Lo que el administrador puede hacer con un pago desde el panel** (O-53, 2026-11-02): darlo por pagado,
 * rechazarlo y reembolsarlo.
 *
 * Tres decisiones que están acá y no en la pantalla:
 *
 *  - **Sólo pagos de citas.** El plan se aprueba contra la pasarela: marcarlo a mano desde el panel daría un
 *    plan sin que haya entrado dinero, y el reembolso de un plan es otra conversación (y otra tabla, `pagos`
 *    del plan). La pantalla no ofrece las acciones y esto lo vuelve a comprobar.
 *  - **Cada cambio deja su evento** en `pagos_eventos`, con origen `manual`, el estado de antes y el de
 *    después, y el motivo: es la bitácora que el panel ya muestra.
 *  - **El reembolso devuelve la plata, no cancela la cita.** Deja el pago en `reembolsado` y **descuenta** lo
 *    devuelto de `cola.monto_pagado` con la misma acción del mostrador (`CobrarCita`), porque la cita deja de
 *    estar pagada por ese monto. El cupo no se libera: cancelar una cita es otra decisión (y otra acción), y
 *    devolver el dinero no debe sacarle el turno al paciente por sorpresa.
 *
 * Si el pago no existe en la base de citas (la cita se borró), el pago igual se aprueba o se reembolsa y el
 * evento queda dicho: el dinero no se puede perder de vista porque falte la fila de la agenda.
 */
final class AccionesDelPago
{
    public function __construct(private CobrarCita $cobrar)
    {
    }

    /**
     * **Dar por pagado** un pago que estaba pendiente o en proceso.
     *
     * Es la conciliación manual: el administrador vio entrar la plata por fuera y lo registra. El pago queda
     * `aprobado` y la cita —si existe— suma lo cobrado a `monto_pagado`, igual que cuando aprueba la pasarela.
     */
    public function marcarPagado(Pago $pago, User $usuario, ?string $motivo = null): Pago
    {
        $this->exigirPagoDeCita($pago);

        if (! in_array($pago->estado, [Pago::PENDIENTE, Pago::EN_PROCESO], true)) {
            throw new RuntimeException('Ese pago ya no está pendiente: no se puede dar por pagado.');
        }

        return DB::transaction(function () use ($pago, $usuario, $motivo) {
            $antes = $pago->estado;

            $pago->estado = Pago::APROBADO;
            $pago->pagado_el = $pago->pagado_el ?: now();
            $pago->save();

            $pago->registrarEvento(PagoEvento::ORIGEN_MANUAL, 'aprobado_manualmente', [
                'por' => $usuario->id,
                'motivo' => $this->motivoLimpio($motivo),
            ], $antes);

            $this->sumarALaCita($pago);

            return $pago;
        });
    }

    /**
     * **Rechazar** un pago que estaba pendiente o en proceso, con el motivo obligatorio.
     *
     * No libera el cupo de una reserva online: eso lo hace la pasarela cuando el pago falla por su cuenta. Acá
     * el pago se cerró a mano y la cita sigue donde estaba; si hay que liberar el turno, se cancela la cita.
     */
    public function rechazar(Pago $pago, User $usuario, string $motivo): Pago
    {
        $this->exigirPagoDeCita($pago);
        $motivo = $this->motivoLimpio($motivo);

        if ($motivo === null) {
            throw new RuntimeException('Escribe el motivo del rechazo: queda en la bitácora del pago.');
        }

        if (! in_array($pago->estado, [Pago::PENDIENTE, Pago::EN_PROCESO], true)) {
            throw new RuntimeException('Sólo se rechaza un pago pendiente o en proceso.');
        }

        return DB::transaction(function () use ($pago, $usuario, $motivo) {
            $antes = $pago->estado;

            $pago->estado = Pago::RECHAZADO;
            $pago->save();

            $pago->registrarEvento(PagoEvento::ORIGEN_MANUAL, 'rechazado_manualmente', [
                'por' => $usuario->id,
                'motivo' => $motivo,
            ], $antes);

            return $pago;
        });
    }

    /**
     * **Reembolsar** un pago aprobado, con el motivo obligatorio.
     *
     * La plata sale por fuera del sistema (la devolución real se hace en la pasarela o en el banco): acá queda
     * el registro, el estado `reembolsado` y la cita deja de contar ese monto como pagado.
     */
    public function reembolsar(Pago $pago, User $usuario, string $motivo): Pago
    {
        $this->exigirPagoDeCita($pago);
        $motivo = $this->motivoLimpio($motivo);

        if ($motivo === null) {
            throw new RuntimeException('Escribe el motivo del reembolso: queda en la bitácora del pago.');
        }

        if ($pago->estado !== Pago::APROBADO) {
            throw new RuntimeException('Sólo se reembolsa un pago aprobado.');
        }

        return DB::transaction(function () use ($pago, $usuario, $motivo) {
            $pago->estado = Pago::REEMBOLSADO;
            $pago->save();

            $pago->registrarEvento(PagoEvento::ORIGEN_MANUAL, 'reembolsado', [
                'por' => $usuario->id,
                'motivo' => $motivo,
                'monto_usd' => Centavos::aDecimal((int) $pago->monto_usd_e2),
            ], Pago::APROBADO);

            $this->restarALaCita($pago);

            return $pago;
        });
    }

    /** Sumar lo cobrado a la cita, como hace la aprobación de la pasarela (`Cobros::aplicarCita`). */
    private function sumarALaCita(Pago $pago): void
    {
        $cita = $pago->cita();

        if (! $cita) {
            $pago->registrarEvento(PagoEvento::ORIGEN_MANUAL, 'cita_inexistente', ['cita_id' => $pago->pagable_id]);

            return;
        }

        $recibido = (float) Centavos::aDecimal((int) $pago->monto_usd_e2);

        if ((float) ($cita->monto ?? 0) <= 0) {
            $this->cobrar->fijarMonto($cita, $recibido, $pago->pagado_el);
        }

        $this->cobrar->fijarPagado($cita, (float) ($cita->monto_pagado ?? 0) + $recibido, $pago->pagado_el);
    }

    /** Descontar lo devuelto: la cita deja de estar pagada por ese monto, y nunca queda en negativo. */
    private function restarALaCita(Pago $pago): void
    {
        $cita = $pago->cita();

        if (! $cita) {
            $pago->registrarEvento(PagoEvento::ORIGEN_MANUAL, 'cita_inexistente', ['cita_id' => $pago->pagable_id]);

            return;
        }

        $devuelto = (float) Centavos::aDecimal((int) $pago->monto_usd_e2);
        $pagado = max(0.0, (float) ($cita->monto_pagado ?? 0) - $devuelto);

        $this->cobrar->fijarPagado($cita, $pagado, now());
    }

    /** Los planes no se tocan desde acá: se aprueban contra la pasarela, no a mano. */
    private function exigirPagoDeCita(Pago $pago): void
    {
        if (! $pago->esDeCita()) {
            throw new RuntimeException('Desde acá sólo se administran los pagos de citas. Los planes se aprueban contra la pasarela.');
        }
    }

    /** El motivo, recortado; `null` si quedó vacío. */
    private function motivoLimpio(?string $motivo): ?string
    {
        $limpio = trim((string) $motivo);

        return $limpio === '' ? null : mb_substr($limpio, 0, 200);
    }
}
