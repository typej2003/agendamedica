<?php

namespace App\Paciente;

/**
 * La confirmación o el cambio de teléfono **en curso** de una cuenta de paciente, mientras escribe el código.
 * Vive en la sesión (igual que `RegistroPendiente`): el código queda hasheado; en local/testing también en claro.
 */
final class TelefonoPendiente
{
    private const CLAVE = 'telefono.pendiente';

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
