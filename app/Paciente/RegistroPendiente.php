<?php

namespace App\Paciente;

/**
 * El registro de un paciente **en curso**, mientras verifica su teléfono.
 *
 * Vive en la sesión y guarda el **hash de la contraseña**, nunca la contraseña en claro. El código queda
 * hasheado; en local/testing se guarda también en claro para poder mostrar el código de desarrollo, igual
 * que en la reserva pública.
 */
final class RegistroPendiente
{
    private const CLAVE = 'registro.pendiente';

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

    public function olvidar(): void
    {
        session()->forget(self::CLAVE);
    }
}
