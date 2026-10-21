<?php

namespace App\RegistroDeMedicos;

/**
 * La solicitud de registro de un médico **en curso**, mientras verifica su correo.
 *
 * Vive en la sesión, igual que `App\Paciente\RegistroPendiente`. El código queda hasheado; en local/testing se
 * guarda también en claro para poder mostrar el código de desarrollo. La foto del carnet ya está en el disco
 * privado (la ruta va acá): una subida no sobrevive a la redirección, así que se guarda al pedir el código.
 */
final class SolicitudEnCurso
{
    private const CLAVE = 'registro_medico.pendiente';

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
