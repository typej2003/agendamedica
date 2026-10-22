<?php

namespace App\Paciente;

use App\Clinica\Agenda\AgendamientoDeCitas;
use App\Clinica\FechaDelConsultorio;
use App\Models\Cola;
use App\Reservas\DisponibilidadPublica;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Reprogramar una cita desde «Mis citas»: **la misma fila** se mueve a otro horario disponible del mismo
 * médico, con la regla de la agenda (`AgendamientoDeCitas::reagendar`) y la cita queda confirmada por el
 * paciente (`estado = 2`).
 *
 * Antes de escribir se vuelve a preguntar cuál es el primer lugar disponible del día elegido
 * (`DisponibilidadPublica`), igual que el portal de reservas: si se ocupó, no se mueve nada. El candado
 * por cita evita que dos pestañas la muevan a la vez.
 */
final class ReagendarCitaDelPaciente
{
    public function __construct(
        private AgendamientoDeCitas $agendamiento,
        private DisponibilidadPublica $disponibilidad,
    ) {
    }

    /**
     * @throws InvalidArgumentException si la cita no se puede gestionar o el horario ya no está.
     */
    public function ejecutar(CitaDelPaciente $cita, string $fecha, string $hora, int $officeId): void
    {
        if (! $cita->puedeGestionarse()) {
            throw new InvalidArgumentException($cita->aviso);
        }

        $fila = Cola::find($cita->id());

        if ($fila === null) {
            throw new InvalidArgumentException('La cita ya no existe.');
        }

        $llave = 'reprogramar-cita:' . $fila->id;

        try {
            $resultado = Cache::lock($llave, 10)->block(5, function () use ($cita, $fila, $fecha, $hora, $officeId) {
                return $this->mover($cita, $fila, $fecha, $hora, $officeId);
            });
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            throw new InvalidArgumentException('Justo se está moviendo esta cita. Intenta de nuevo en unos segundos.');
        }

        if (! $resultado['ok']) {
            throw new InvalidArgumentException($resultado['mensaje']);
        }
    }

    /** @return array{ok: bool, mensaje: string} */
    private function mover(CitaDelPaciente $cita, Cola $fila, string $fecha, string $hora, int $officeId): array
    {
        $inicio = Carbon::createFromFormat(
            'Y-m-d H:i',
            $fecha . ' ' . substr($hora, 0, 5),
            FechaDelConsultorio::zona(),
        );

        if ($inicio->lessThan(FechaDelConsultorio::ahora()->copy()->addHours(PuedeGestionarSuCita::HORAS_MINIMAS))) {
            return [
                'ok' => false,
                'mensaje' => 'Elige un horario con al menos ' . PuedeGestionarSuCita::HORAS_MINIMAS . ' horas de anticipación.',
            ];
        }

        // El bloque elegido (hora y sede) debe seguir ofreciéndose con la agenda de este momento.
        if ($this->disponibilidad->opcionEn($cita->medico, $fecha, $hora, $officeId) === null) {
            return ['ok' => false, 'mensaje' => 'Ese horario se acaba de ocupar. Elige otro de los que se muestran.'];
        }

        $resultado = $this->agendamiento->reagendar(
            ['reg_medico' => $fila->reg_medico, 'medico' => $cita->medico],
            $fila,
            [
                'sede' => $officeId,
                'fecha' => $fecha,
                'hora' => $hora,
                // La razón y el monto de la cita no se pierden al moverla.
                'motivo' => $fila->tipo,
                'monto' => $fila->monto,
            ],
            ['estado' => Cola::ESTADO_CONFIRMADA_PACIENTE],
        );

        return [
            'ok' => true,
            'mensaje' => $resultado->tipo === \App\Clinica\Agenda\ResultadoDeAgendamiento::SIN_CAMBIOS
                ? 'La cita ya estaba en ese horario.'
                : 'Cita reprogramada.',
        ];
    }
}
