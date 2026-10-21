<?php

namespace App\RegistroDeMedicos;

use App\Mail\RegistroDeMedico\CodigoDeRegistroDeMedico;
use App\Mail\RegistroDeMedico\NuevaSolicitudDeMedico;
use App\Mail\RegistroDeMedico\SolicitudDeMedicoAprobada;
use App\Mail\RegistroDeMedico\SolicitudDeMedicoRechazada;
use App\Mail\RegistroDeMedico\SolicitudDeMedicoRecibida;
use App\Models\SolicitudMedico;
use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * **Los correos del registro de médicos**, en un solo lugar.
 *
 * Ninguno es transaccional con la base: si el SMTP falla, la solicitud igual queda guardada (o aprobada, o
 * rechazada) y el error se registra. Por eso la clave temporal de una aprobación **también** se le muestra al
 * administrador una vez: si el correo no llega, se la puede pasar a mano. Cada método devuelve si salió.
 */
final class CorreosDeLaSolicitud
{
    public function codigo(string $email, string $nombre, string $codigo): bool
    {
        return $this->mandar($email, new CodigoDeRegistroDeMedico($nombre, $codigo));
    }

    /** Al médico, «Recibimos tu solicitud», y a cada administrador activo, el aviso de que hay una nueva. */
    public function recibida(SolicitudMedico $solicitud): void
    {
        $this->mandar($solicitud->email, new SolicitudDeMedicoRecibida($solicitud));

        User::administradores()
            ->where('is_active', true)
            ->pluck('email')
            ->filter()
            ->unique()
            ->each(fn (string $email) => $this->mandar($email, new NuevaSolicitudDeMedico($solicitud)));
    }

    public function aprobada(SolicitudMedico $solicitud, string $clave): bool
    {
        return $this->mandar($solicitud->email, new SolicitudDeMedicoAprobada($solicitud, $clave));
    }

    public function rechazada(SolicitudMedico $solicitud): bool
    {
        return $this->mandar($solicitud->email, new SolicitudDeMedicoRechazada($solicitud));
    }

    private function mandar(string $email, Mailable $correo): bool
    {
        try {
            Mail::to($email)->send($correo);

            return true;
        } catch (Throwable $e) {
            Log::warning('Registro de médicos: no salió un correo', [
                'correo' => class_basename($correo),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
