<?php

namespace App\Paciente;

use App\Models\User;

/**
 * «Usar otro número»: descarta el código de teléfono en curso de la cuenta para volver al paso de elegir el número
 * al instante, sin esperar a que venza. No envía nada, así que no gasta códigos del límite por hora
 * (`LimitesDeReserva`). Sólo olvida el trámite si es de esta cuenta.
 */
final class DescartarCodigoDeTelefono
{
    public function __construct(private TelefonoPendiente $pendiente)
    {
    }

    /** @return bool true si había un trámite de la cuenta y se descartó */
    public function ejecutar(User $user): bool
    {
        $datos = $this->pendiente->datos();

        if ($datos === null || (int) ($datos['user_id'] ?? 0) !== (int) $user->id) {
            return false;
        }

        $this->pendiente->olvidar();

        return true;
    }
}
