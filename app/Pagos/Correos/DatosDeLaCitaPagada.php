<?php

namespace App\Pagos\Correos;

use App\Models\Cola;
use App\Models\Paciente;
use App\Models\Pago;
use App\Paciente\CitaDelPaciente;
use App\Support\HoraLocal;

/**
 * Los datos de una cita pagada, ya resueltos para los dos correos.
 *
 * La fuente es `CitaDelPaciente`, la misma clase con la que «Mis citas» y las pantallas del paciente
 * muestran al médico, la sede, la dirección, el día y la hora. No hay una segunda lectura de la cita:
 * `CitaDelPaciente` se arma con `ArmadorDeAgenda`, que es el único lugar donde una fila de `cola` se
 * resuelve a una cita legible (incluida la sede de las citas viejas, que sale de la historia).
 *
 * **Privacidad:** acá no entra el motivo de la consulta ni ningún dato clínico. El correo al paciente
 * sólo necesita lo que le sirve para llegar y su comprobante; el del médico lleva el nombre del paciente
 * porque es su paciente.
 */
final class DatosDeLaCitaPagada
{
    public function __construct(
        /** Nombre completo del paciente (o lo que la cita tenga, si no hay ficha). */
        public readonly string $paciente,
        public readonly string $medico,
        public readonly string $especialidades,
        /** «Jueves 22 de octubre de 2026», en español. */
        public readonly string $fecha,
        /** «22/10», para los asuntos. */
        public readonly string $fechaCorta,
        /** «9:00 AM», en el reloj del consultorio (un correo no tiene dispositivo). */
        public readonly string $hora,
        public readonly string $sede,
        public readonly string $direccion,
        public readonly string $monto,
        public readonly string $montoUsd,
        public readonly string $referencia,
        public readonly string $canal,
    ) {
    }

    public static function desde(Pago $pago, Cola $cita, CitaDelPaciente $vista, ?Paciente $paciente): self
    {
        return new self(
            paciente: self::nombreDelPaciente($paciente) ?: $vista->cita->paciente,
            medico: $vista->medico->nombre_mostrar,
            especialidades: $vista->especialidades(),
            fecha: $vista->dia(),
            fechaCorta: $cita->fecha->format('d/m'),
            hora: HoraLocal::hora12($vista->hora()) ?: '—',
            sede: $vista->sede(),
            direccion: $vista->direccion(),
            monto: $pago->montoTexto(),
            montoUsd: $pago->montoUsdTexto(),
            referencia: self::referencia($pago),
            canal: self::canal($pago->canal),
        );
    }

    /**
     * El número con el que el paciente reconoce su pago: el de la pasarela si lo hay (es el que vio en la
     * pantalla pública) y, si no, el interno del pago.
     */
    public static function referencia(Pago $pago): string
    {
        $referencia = $pago->referencia_externa ?: $pago->internal_id ?: $pago->referencia;

        return $referencia !== null && trim((string) $referencia) !== '' ? (string) $referencia : '—';
    }

    /** De dónde vino el cobro, en palabras: el médico no tiene que interpretar el código del canal. */
    public static function canal(?string $canal): string
    {
        return match ($canal) {
            Pago::CANAL_LINK => 'link de la agenda',
            Pago::CANAL_RESERVA_ONLINE => 'reserva online',
            Pago::CANAL_MOSTRADOR => 'mostrador',
            default => (string) $canal,
        };
    }

    private static function nombreDelPaciente(?Paciente $paciente): string
    {
        if ($paciente === null) {
            return '';
        }

        return trim(($paciente->nombres ?? '') . ' ' . ($paciente->apellidos ?? ''));
    }
}
