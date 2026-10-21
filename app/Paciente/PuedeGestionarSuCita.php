<?php

namespace App\Paciente;

use App\Clinica\Agenda\CitaDeAgenda;
use App\Clinica\FechaDelConsultorio;
use App\Reservas\OpcionDeReserva;
use Carbon\Carbon;

/**
 * **Qué puede hacer el paciente con una cita suya desde «Mis citas»** (decisión del 2026-10-19, v1):
 *
 *  - sólo citas **futuras**, no atendidas, no movidas por el escritorio y sin pago registrado;
 *  - con al menos **24 horas** de anticipación, para que el consultorio pueda reusar el cupo.
 *
 * La regla vive acá y no en cada pantalla: la usan el listado (para mostrar u ocultar los botones) y las
 * dos acciones (cancelar y reprogramar) para volver a comprobarla antes de escribir. Es una decisión de
 * producto fácil de cambiar: `HORAS_MINIMAS` es el único número.
 */
final class PuedeGestionarSuCita
{
    public const HORAS_MINIMAS = 24;

    /** El aviso para el paciente, o `''` si puede gestionar la cita. */
    public static function aviso(CitaDeAgenda $cita): string
    {
        if ($cita->movida()) {
            return 'El consultorio movió esta cita a otro día. Revisa la cita nueva o comunícate con el consultorio.';
        }

        if ($cita->atendida()) {
            return 'Esta cita ya fue atendida.';
        }

        if ((float) ($cita->montoPagado ?? 0) > 0) {
            return 'Esta cita tiene un pago registrado. Para cambiarla, comunícate con el consultorio.';
        }

        $inicio = self::inicio($cita);
        $ahora = FechaDelConsultorio::ahora();

        if ($inicio->lessThanOrEqualTo($ahora)) {
            return 'Esta cita ya pasó.';
        }

        if ($inicio->lessThan($ahora->copy()->addHours(self::HORAS_MINIMAS))) {
            return 'Faltan menos de ' . self::HORAS_MINIMAS . ' horas para la cita. Para cambiarla, comunícate con el consultorio.';
        }

        return '';
    }

    public static function puede(CitaDeAgenda $cita): bool
    {
        return self::aviso($cita) === '';
    }

    /**
     * **El horario nuevo también tiene que respetar las 24 horas.** Si el paciente reprograma para mañana
     * a primera hora, la cita quedaría otra vez dentro de la ventana y ya no podría volver a gestionarla.
     */
    public static function opcionPermitida(OpcionDeReserva $opcion): bool
    {
        return $opcion->inicio()->greaterThanOrEqualTo(
            FechaDelConsultorio::ahora()->copy()->addHours(self::HORAS_MINIMAS)
        );
    }

    private static function inicio(CitaDeAgenda $cita): Carbon
    {
        $hora = $cita->horaIni !== null ? substr((string) $cita->horaIni, 0, 5) : '08:00';

        return Carbon::createFromFormat(
            'Y-m-d H:i',
            $cita->fecha . ' ' . $hora,
            FechaDelConsultorio::zona(),
        );
    }
}
