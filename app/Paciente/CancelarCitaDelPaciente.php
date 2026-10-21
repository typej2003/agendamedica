<?php

namespace App\Paciente;

use App\Actions\Agenda\EliminarCita;
use App\Models\Cola;
use InvalidArgumentException;

/**
 * Cancelar una cita desde «Mis citas»: la decisión del paciente, con las reglas de
 * `PuedeGestionarSuCita` (24 h, futura, sin atender y sin pago) y el borrado por `EliminarCita`, la misma
 * Action de la agenda. No se borra a mano: la fila tiene que dejar su rastro en `sync_changes`.
 */
final class CancelarCitaDelPaciente
{
    public function __construct(private EliminarCita $eliminar)
    {
    }

    /** @throws InvalidArgumentException con el motivo para la pantalla. */
    public function ejecutar(CitaDelPaciente $cita): void
    {
        if (! $cita->puedeGestionarse()) {
            throw new InvalidArgumentException($cita->aviso);
        }

        $fila = Cola::find($cita->id());

        if ($fila === null) {
            throw new InvalidArgumentException('La cita ya no existe.');
        }

        $this->eliminar->ejecutar($fila);
    }
}
