<?php

namespace App\Reservas;

use Carbon\Carbon;

/**
 * Un horario que el portal público le ofrece al paciente: **fecha + hora + sede**.
 *
 * No es una fila de la base: sale de `DisponibilidadPublica`, que aplica las mismas reglas que la agenda
 * real (`ArmadorDeAgenda` + `Jornada`). La clave es lo que viaja en el formulario y lo que se vuelve a
 * comprobar antes de crear la cita: si la agenda cambió entre que se mostró y se confirmó, no coincide.
 */
final class OpcionDeReserva
{
    public function __construct(
        public readonly string $fecha,        // Y-m-d
        public readonly string $hora,         // H:i
        public readonly int $officeId,
        public readonly ?int $centroId,
        public readonly string $sede,
        public readonly string $direccion,
        public readonly int $numorden,
    ) {
    }

    /** La identidad del horario elegido: es lo que se compara al confirmar. */
    public function clave(): string
    {
        return $this->fecha . '|' . $this->hora . '|' . $this->officeId;
    }

    /** "Lunes 20 de octubre" (con la locale en español, como el resto de la web). */
    public function dia(): string
    {
        return ucfirst(Carbon::createFromFormat('!Y-m-d', $this->fecha)->locale('es')->isoFormat('dddd D [de] MMMM'));
    }

    /** "8:00 a. m." */
    public function hora12(): string
    {
        return Carbon::createFromFormat('H:i', $this->hora)->format('g:i A');
    }
}
