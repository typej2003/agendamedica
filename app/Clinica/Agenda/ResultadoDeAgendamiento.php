<?php

namespace App\Clinica\Agenda;

use App\Models\Cola;
use App\Models\Office;
use Carbon\Carbon;

/**
 * Cómo terminó un intento de agendar o reagendar una cita desde la web. El controlador lo traduce a una
 * respuesta (redirección con el mensaje, o el formulario con la pregunta por la cita pendiente).
 */
final class ResultadoDeAgendamiento
{
    public const PREGUNTA = 'pregunta';        // el paciente ya tiene una cita pendiente: se decide antes de escribir
    public const CANCELADO = 'cancelado';      // el usuario eligió no agendar
    public const MOVIDA = 'movida';            // se movió la cita pendiente a la fecha nueva
    public const CREADA = 'creada';            // se agendó una cita nueva
    public const ACTUALIZADA = 'actualizada';  // se editó/reagendó la cita
    public const SIN_CAMBIOS = 'sin_cambios';  // la edición no cambiaba nada

    /**
     * @param  array<string,mixed>  $valores  lo validado del formulario (para volver a mostrarlo)
     * @param  array{paciente: ?\App\Models\Paciente, numhistoria: ?int}|null  $objetivo  a quién se le agenda
     * @param  array<string,mixed>|null  $pendiente  la cita pendiente que ya tiene (solo en `PREGUNTA`)
     * @param  Cola|null  $cita  la fila recién creada (solo en `CREADA`; la usa el portal público para
     *                            mostrar la confirmación)
     */
    public function __construct(
        public readonly string $tipo,
        public readonly Carbon $fecha,
        public readonly ?Office $sede,
        public readonly array $valores = [],
        public readonly ?array $objetivo = null,
        public readonly ?array $pendiente = null,
        public readonly ?Cola $cita = null,
    ) {
    }

    public function esPregunta(): bool
    {
        return $this->tipo === self::PREGUNTA;
    }

    /** El mensaje de éxito de la redirección; `null` para la pregunta, que no redirige. */
    public function mensaje(): ?string
    {
        return match ($this->tipo) {
            self::CANCELADO   => 'No se agendó nada.',
            self::MOVIDA      => 'Se movió la cita que ya tenía.',
            self::CREADA      => 'Cita agendada.',
            self::ACTUALIZADA => 'Cita actualizada.',
            self::SIN_CAMBIOS => 'No había nada que cambiar.',
            default           => null,
        };
    }
}
