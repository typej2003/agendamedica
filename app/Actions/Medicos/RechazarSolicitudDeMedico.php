<?php

namespace App\Actions\Medicos;

use App\Models\SolicitudMedico;
use App\Models\User;
use App\RegistroDeMedicos\CorreosDeLaSolicitud;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * **Rechazar la solicitud de registro de un médico**, con el motivo que le llega por correo. La solicitud queda
 * guardada (es el rastro de la revisión) y no impide que vuelva a postularse con los datos corregidos.
 */
final class RechazarSolicitudDeMedico
{
    public function __construct(private CorreosDeLaSolicitud $correos)
    {
    }

    /** @return bool si salió el correo */
    public function ejecutar(SolicitudMedico $solicitud, User $revisor, string $motivo): bool
    {
        $motivo = trim($motivo);
        if ($motivo === '') {
            throw ValidationException::withMessages(['motivo' => 'Escribe el motivo: es lo que le llega al médico.']);
        }

        $solicitud = DB::transaction(function () use ($solicitud, $revisor, $motivo) {
            $solicitud = SolicitudMedico::whereKey($solicitud->id)->lockForUpdate()->firstOrFail();

            if (! $solicitud->estaPendiente()) {
                throw ValidationException::withMessages(['solicitud' => 'Esta solicitud ya fue revisada.']);
            }

            $solicitud->update([
                'estado' => SolicitudMedico::RECHAZADA,
                'motivo_rechazo' => $motivo,
                'revisada_por' => $revisor->id,
                'revisada_el' => now(),
            ]);

            return $solicitud;
        });

        return $this->correos->rechazada($solicitud);
    }
}
