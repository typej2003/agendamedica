<?php

namespace App\RegistroDeMedicos;

/**
 * La solicitud de registro de un médico **en curso**, mientras verifica su correo.
 *
 * Vive en la sesión, igual que `App\Paciente\RegistroPendiente`. El código queda hasheado; en local/testing se
 * guarda también en claro para poder mostrar el código de desarrollo. La foto del carnet ya está en el disco
 * privado (la ruta va acá): una subida no sobrevive a la redirección, así que se guarda al pedir el código.
 *
 * La foto va en **su propia clave** desde el 2026-10-24: antes vivía dentro del trámite, que sólo se guarda
 * cuando el correo sale, así que un campo con error o un envío fallido la borraban y el médico tenía que
 * elegirla otra vez.
 */
final class SolicitudEnCurso
{
    private const CLAVE = 'registro_medico.pendiente';

    private const CLAVE_CARNET = 'registro_medico.carnet';

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

    /**
     * La foto ya subida de este trámite (con el correo para el que se subió).
     *
     * @param  array<string,mixed>  $carnet
     */
    public function recordarCarnet(array $carnet): void
    {
        session([self::CLAVE_CARNET => $carnet]);
    }

    /** @return array<string,mixed>|null */
    public function carnet(): ?array
    {
        $carnet = session(self::CLAVE_CARNET);

        return is_array($carnet) ? $carnet : null;
    }

    public function olvidarCarnet(): void
    {
        session()->forget(self::CLAVE_CARNET);
    }

    /** Al cerrar el trámite (solicitud guardada) se olvidan las dos cosas. */
    public function olvidar(): void
    {
        session()->forget([self::CLAVE, self::CLAVE_CARNET]);
    }
}
