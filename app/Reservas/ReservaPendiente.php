<?php

namespace App\Reservas;

use App\Models\Medico;

/**
 * La reserva **en curso** mientras el paciente verifica su teléfono.
 *
 * Vive en la sesión, no en una tabla: son datos de un trámite de minutos y no hay nada que consultar
 * después. Guarda el hash del código (nunca el código en claro, salvo en local/testing para poder
 * verificar el flujo) y el horario elegido, que se vuelve a comprobar antes de crear la cita.
 */
final class ReservaPendiente
{
    private const CLAVE = 'reservas.pendiente';

    /** @param array<string,mixed> $datos */
    public function guardar(array $datos): void
    {
        session([self::CLAVE => $datos]);
    }

    /** @return array<string,mixed>|null */
    public function datos(): ?array
    {
        $datos = session(self::CLAVE);

        return is_array($datos) ? $datos : null;
    }

    /** @return array<string,mixed>|null */
    public function para(Medico $medico): ?array
    {
        $datos = $this->datos();

        return $datos !== null && (int) ($datos['medico_id'] ?? 0) === (int) $medico->id ? $datos : null;
    }

    public function olvidar(): void
    {
        session()->forget(self::CLAVE);
    }
}
