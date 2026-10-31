<?php

namespace App\Reservas;

use App\Actions\Pagos\GenerarLinkDePagoDeCita;
use App\Clinica\CitaOnline\ConfiguracionDeCitaOnline;
use App\Models\Cola;
use App\Models\Evolucion;
use App\Models\Medico;
use App\Models\Pago;
use App\Pagos\Centavos;
use App\Pagos\Cobros;
use App\Pagos\Porcentaje;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * **El cobro al reservar** (2026-10-30): después de crear la cita del portal público, deja la intención de
 * pago con el porcentaje de «Cita Online» del médico y **30 minutos** para pagarla.
 *
 * No inventa un cobro: si el médico cobra 0 %, la cita no tiene precio, el porcentaje redondea a cero o no
 * hay método de pago de la plataforma configurado, devuelve `null` y la reserva sigue confirmada como
 * siempre. La pantalla pública y el motor de cobro ya existían; esto sólo decide **cuánto** y **hasta
 * cuándo**, y delega la creación en `Cobros::crearParaCita()`.
 *
 * El canal es `reserva_online`, así que la comisión de cita online se aplica sola.
 */
final class CobroAlReservar
{
    /** El plazo que ve el paciente para pagar su reserva. */
    public const MINUTOS_PARA_PAGAR = 30;

    public function __construct(
        private readonly Cobros $cobros,
        private readonly GenerarLinkDePagoDeCita $links,
    ) {
    }

    /**
     * El pago pendiente de la reserva, o `null` cuando no corresponde cobrar.
     *
     * No lanza: un cobro que no se puede crear nunca puede tumbar la reserva, que ya está hecha. El motivo
     * queda en el log para poder revisarlo.
     */
    public function ejecutar(Medico $medico, Cola $cita): ?Pago
    {
        $configuracion = ConfiguracionDeCitaOnline::de(
            Evolucion::where('reg_medico', $medico->registroDelFormato())->first(),
        );

        $porcentajeBp = $configuracion->porcentajeBp();

        // `cola.monto` es el precio de la cita (el que salió del motivo) en dólares decimales.
        $precioUsdE2 = Centavos::deDecimal($cita->monto);

        if ($porcentajeBp <= 0 || $precioUsdE2 <= 0) {
            return null;
        }

        $montoUsdE2 = Porcentaje::aplicar($precioUsdE2, $porcentajeBp);

        if ($montoUsdE2 <= 0) {
            return null;
        }

        // La misma consulta que usa el link de la agenda: el método de la plataforma para cobros.
        $metodo = $this->links->metodo();

        if ($metodo === null) {
            Log::warning('Reserva online sin cobro: no hay método de pago de la plataforma configurado.', [
                'cita_id' => $cita->id,
                'reg_medico' => $cita->reg_medico,
            ]);

            return null;
        }

        try {
            return $this->cobros->crearParaCita(
                $cita,
                $metodo,
                $montoUsdE2,
                Pago::CANAL_RESERVA_ONLINE,
                null,
                now()->addMinutes(self::MINUTOS_PARA_PAGAR),
            );
        } catch (Throwable $e) {
            // Falta la tasa, el método se desconfiguró entre la consulta y la creación, etc. La cita ya
            // está reservada: se avisa en el log y el paciente sigue al comprobante de siempre.
            Log::warning('Reserva online sin cobro: no se pudo crear el pago.', [
                'cita_id' => $cita->id,
                'reg_medico' => $cita->reg_medico,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
