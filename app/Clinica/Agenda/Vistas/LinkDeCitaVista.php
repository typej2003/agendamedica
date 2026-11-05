<?php

namespace App\Clinica\Agenda\Vistas;

use App\Models\Cola;
use App\Reservas\EnlaceDeCitaCompartida;

/**
 * **La pantalla «Compartir la cita»** (O-52): lo que muestra el link firmado de la ficha pública con la fecha y
 * el motivo ya elegidos, con su QR y su vigencia.
 *
 * Sin base ni HTTP: recibe la cita y lo ya resuelto (`EnlaceDeCitaCompartida` y el SVG del QR) y devuelve textos
 * y URLs. Lo único que cuenta es que el link **no** lleve datos clínicos: el paciente ve su propia ficha.
 */
final class LinkDeCitaVista
{
    public function __construct(
        private Cola $cita,
        private ?string $link,
        private ?string $qr,
        private string $paciente,
        private ?string $fecha,
        private ?string $hora,
        private ?string $motivo,
    ) {
    }

    /** ¿Se pudo armar el link? Sin médico en la cita, no hay ficha pública que abrir. */
    public function hayLink(): bool
    {
        return $this->link !== null;
    }

    public function link(): string
    {
        return (string) $this->link;
    }

    /** El SVG del QR, listo para incrustar (`{!! !!}`), o `null`. */
    public function qr(): ?string
    {
        return $this->qr;
    }

    /** El nombre del paciente de la cita, **el mismo de la fila de la agenda** (`PacientesDeCita::nombreDeLaCita()`). */
    public function paciente(): string
    {
        return $this->paciente;
    }

    /** La fecha de la cita, ya legible («lunes 3 de noviembre»), o `null`. */
    public function fecha(): ?string
    {
        if ($this->fecha === null) {
            return null;
        }

        return ucfirst(\Carbon\Carbon::createFromFormat('!Y-m-d', $this->fecha)->locale('es')->isoFormat('dddd D [de] MMMM'));
    }

    public function hora(): ?string
    {
        return $this->hora;
    }

    /** El motivo de la cita, si lo tiene. */
    public function motivo(): ?string
    {
        return $this->motivo;
    }

    /** «El link vence en 7 días y no reserva por sí solo: el paciente confirma con su código.» */
    public function vigencia(): string
    {
        return 'El link vence en ' . EnlaceDeCitaCompartida::DIAS . ' días y no reserva por sí solo: el paciente confirma con su código.';
    }

    public function mensaje(): string
    {
        return 'Esta cita no tiene un médico asignado, así que no hay una ficha pública que compartir. Asígnale uno y vuelve a intentarlo.';
    }

    public function urlVolver(): string
    {
        return route('clinica.agenda', ['fecha' => $this->cita->fecha]);
    }
}
