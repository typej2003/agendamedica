<?php

namespace App\Actions\Consulta;

/**
 * Qué pasó al **terminar una consulta** (WEB-4.8).
 *
 * Tiene tres desenlaces y los tres son normales, no errores: se terminó y la cita quedó atendida; la
 * consulta no tiene cita de ese día (una consulta abierta desde la historia, sin agenda detrás), o la
 * cita del día la había movido el escritorio y no se marca. La pantalla necesita distinguirlos porque
 * dicen cosas distintas: "la cita quedó atendida" no es lo mismo que "no había cita que marcar".
 *
 * El que **sí** es un error —la cita sin historia— no llega acá: lo lanza `AtenderCita`
 * (`App\Actions\Agenda\CitaNoAtendible`) y el controlador lo convierte en un aviso.
 */
final class ConsultaCerrada
{
    public const ATENDIDA = 'atendida';
    public const SIN_CITA = 'sin_cita';
    public const CITA_MOVIDA = 'cita_movida';

    private function __construct(
        public readonly string $resultado,
        public readonly ?int $citaId,
    ) {
    }

    /** La cita del día quedó marcada como atendida. */
    public static function atendida(int $citaId): self
    {
        return new self(self::ATENDIDA, $citaId);
    }

    /** No hay ninguna cita de la agenda para este paciente en el día de la consulta. */
    public static function sinCita(): self
    {
        return new self(self::SIN_CITA, null);
    }

    /**
     * La cita del día la movió el escritorio: esa fila es la vieja y el paciente ya está en la fecha
     * nueva, así que la consulta se termina pero no se marca nada.
     */
    public static function citaMovida(int $citaId): self
    {
        return new self(self::CITA_MOVIDA, $citaId);
    }

    public function quedoAtendida(): bool
    {
        return $this->resultado === self::ATENDIDA;
    }

    /** El mensaje para la franja de la pantalla, ya en español y diciendo qué pasó. */
    public function mensaje(string $numeroDeConsulta): string
    {
        if ($this->resultado === self::ATENDIDA) {
            return 'Consulta N.º ' . $numeroDeConsulta . ' terminada: la cita quedó atendida.';
        }

        if ($this->resultado === self::CITA_MOVIDA) {
            return 'Consulta N.º ' . $numeroDeConsulta . ' terminada. La cita de ese día se había movido '
                . 'a otro día, así que no se marcó como atendida.';
        }

        return 'Consulta N.º ' . $numeroDeConsulta . ' terminada. No se encontró una cita de la agenda de '
            . 'ese día para marcarla como atendida.';
    }
}
