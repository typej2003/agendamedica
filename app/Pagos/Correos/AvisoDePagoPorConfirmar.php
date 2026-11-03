<?php

namespace App\Pagos\Correos;

use App\Mail\PagoMovilPorConfirmar;
use App\Models\Medico;
use App\Models\Pago;
use App\Models\PagoEvento;
use App\Models\User;
use App\Notificaciones\Correo;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * El aviso de que **hay un pago móvil esperando confirmación**: al médico cuando la plata entra a su
 * cuenta, y a los administradores cuando la recauda la plataforma (el médico no ve esa cuenta).
 *
 * Se manda después de guardar la declaración: si el SMTP está caído, el pago sigue pendiente y el error
 * queda en la bitácora. Cada destinatario se intenta por separado.
 */
final class AvisoDePagoPorConfirmar
{
    public function enviar(Pago $pago): void
    {
        try {
            $destinos = $this->destinos($pago);

            if ($destinos === []) {
                $pago->registrarEvento(PagoEvento::ORIGEN_SISTEMA, 'correo_omitido', [
                    'destinatario' => 'confirmador',
                    'motivo' => 'sin_correo',
                ]);

                return;
            }

            foreach ($destinos as $email) {
                try {
                    Mail::to($email)->send(new PagoMovilPorConfirmar($pago));
                } catch (Throwable $e) {
                    report($e);
                    $pago->registrarEvento(PagoEvento::ORIGEN_SISTEMA, 'correo_error', [
                        'destinatario' => 'confirmador',
                        'mensaje' => $e->getMessage(),
                    ]);
                }
            }
        } catch (Throwable $e) {
            report($e);
            $pago->registrarEvento(PagoEvento::ORIGEN_SISTEMA, 'correo_error', [
                'destinatario' => 'confirmador',
                'mensaje' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Quién puede confirmar: el médico del consultorio si recauda él; los Root si recauda la plataforma.
     *
     * @return list<string>
     */
    private function destinos(Pago $pago): array
    {
        if ($pago->recaudador === Pago::RECAUDADOR_MEDICO) {
            $email = Correo::valido(
                Medico::where('reg_medico', $pago->beneficiario_reg_medico)->value('email')
            );

            return $email !== null ? [$email] : [];
        }

        return User::role('Root')
            ->pluck('email')
            ->map(fn (?string $email): ?string => Correo::valido($email))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
