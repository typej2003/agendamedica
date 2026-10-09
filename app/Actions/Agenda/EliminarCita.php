<?php

namespace App\Actions\Agenda;

use App\Models\Cola;
use InvalidArgumentException;

/**
 * Eliminar una cita de la agenda.
 *
 * **La regla es una decisión, no paridad** (TODO-WEB.md, WEB-2.3): el escritorio exige
 * `atendido = 0` y **no mira el pago** (`w_hacer_cita.srw:1210`), y Alexander pidió que una cita
 * pagada no se pueda eliminar. Acá valen las dos:
 *
 *  - una cita **atendida** no se elimina: tiene la consulta colgando (la regla "cita atendida ⇒
 *    tiene historia", TODO.md §9); y
 *  - una cita **con pago registrado** (pagada o abonada) no se elimina: el cobro ya ocurrió y
 *    borrarlo sería perder el registro de la plata.
 *
 * El borrado en sí lo deja anotado `Cola::booted()` en `sync_changes` (`operation = deleted`), que
 * es lo que le permite al móvil y al escritorio enterarse de que la fila ya no existe.
 *
 * ⚠️ El móvil **todavía no la aplica**: su "Eliminar" llega por el camino genérico del sync, que no
 * pasa por acá (ver `ROADMAP.md` §1.4, "eliminar de la cola solo si el paciente no pagó").
 */
final class EliminarCita
{
    /** @throws InvalidArgumentException con el motivo, para mostrarlo en pantalla. */
    public function ejecutar(Cola $cita): void
    {
        if ($cita->movida_escritorio) {
            throw new InvalidArgumentException(
                'El escritorio movió esta cita a otro día: el paciente ya está en la fecha nueva.'
            );
        }

        if ((int) $cita->atendido === 1) {
            throw new InvalidArgumentException(
                'Una cita ya atendida no se elimina: la consulta queda colgando de ella.'
            );
        }

        if ((float) ($cita->monto_pagado ?? 0) > 0) {
            throw new InvalidArgumentException(
                'Una cita con un pago registrado no se elimina. Si fue un error, devolvé el cobro primero.'
            );
        }

        $cita->delete();
    }
}
