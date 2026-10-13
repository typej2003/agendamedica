<?php

namespace App\Cumpleanos;

use App\Models\Paciente;
use App\Notificaciones\Canal;
use App\Notificaciones\Correo;
use App\Notificaciones\Telefono;
use Carbon\CarbonInterface;

/**
 * Un paciente del médico del contexto que **cumple años el día que se está saludando** (WEB-2.9): el
 * paciente, la fecha del cumpleaños que se saluda y la edad que cumple.
 *
 * Se llama "cumpleaño" y no "cumpleañero" a propósito: el objeto modela **el cumpleaños de esta
 * persona en esta fecha**, no a la persona. Un paciente puede tener una fila acá un año y ninguna el
 * siguiente (si la fecha no vuelve a caer, que solo pasa con el 29 de febrero).
 *
 * No consulta la base ni sabe de HTTP: lo arma `CumpleanosDeHoy` con la fila ya leída, así que se
 * prueba sin base de datos.
 */
final class Cumpleano
{
    public function __construct(
        private Paciente $paciente,
        /** El día que se está saludando (el del consultorio, no el del servidor). */
        private CarbonInterface $dia,
        /** `true` si hoy cae el día real de nacimiento; `false` cuando el 29/02 se adelanta al 28. */
        private bool $esElDiaExacto,
    ) {
    }

    public function paciente(): Paciente
    {
        return $this->paciente;
    }

    public function pacienteId(): int
    {
        return (int) $this->paciente->id;
    }

    public function dia(): CarbonInterface
    {
        return $this->dia;
    }

    /** La fecha que se guarda en el registro del envío (`notificaciones_cumple.fecha_cumple`). */
    public function fecha(): string
    {
        return $this->dia->toDateString();
    }

    /** `Pérez, Ana` — apellido primero, como el resto de la web. */
    public function nombre(): string
    {
        return trim(($this->paciente->apellidos ?? '') . ', ' . ($this->paciente->nombres ?? ''), ', ');
    }

    /** `Ana Pérez` — como se saluda a alguien en un mensaje. */
    public function nombreParaElSaludo(): string
    {
        return trim(($this->paciente->nombres ?? '') . ' ' . ($this->paciente->apellidos ?? '')) ?: 'paciente';
    }

    /**
     * La edad que **cumple** hoy, o `null` si la fecha de nacimiento no es usable (el legado trae
     * `0000-00-00` y basura). Se calcula contra el día que se saluda, no contra "ahora": el comando con
     * `--fecha` tiene que dar la misma edad que el día real.
     */
    public function edad(): ?int
    {
        $anio = Cumpleanos::anioDeNacimiento($this->paciente->fnacimiento);

        if ($anio === null) {
            return null;
        }

        $edad = $this->dia->year - $anio;

        return $edad >= 0 ? $edad : null;
    }

    public function telefono(): ?string
    {
        return $this->paciente->telefono ?: null;
    }

    public function correo(): ?string
    {
        return $this->paciente->email ?: null;
    }

    public function tieneTelefono(): bool
    {
        return Telefono::internacional($this->paciente->telefono) !== null;
    }

    public function tieneCorreo(): bool
    {
        return Correo::valido($this->paciente->email) !== null;
    }

    /**
     * ¿Se le puede mandar el saludo por este canal? Es la regla de `Canal::usaCorreo` aplicada al
     * paciente: el correo necesita correo válido y los otros dos, un teléfono interpretable.
     */
    public function puedeRecibirPor(?string $canal): bool
    {
        return Canal::usaCorreo($canal) ? $this->tieneCorreo() : $this->tieneTelefono();
    }

    /** El dato que le falta para el canal elegido, con el nombre que usa el recordatorio de cita. */
    public function datoQueFalta(?string $canal): ?string
    {
        if ($this->puedeRecibirPor($canal)) {
            return null;
        }

        return Canal::usaCorreo($canal) ? 'correo' : 'teléfono';
    }

    /** El mensaje de error del dato faltante, igual al que da `EnviarRecordatorio::destino`. */
    public function mensajeDeDatoFaltante(?string $canal): string
    {
        return $this->datoQueFalta($canal) === 'correo'
            ? 'El paciente no tiene un correo válido registrado.'
            : 'El paciente no tiene un teléfono válido registrado.';
    }

    /** `true` cuando el saludo es por el 29/02 adelantado al 28: la pantalla lo dice. */
    public function esAdelantado(): bool
    {
        return ! $this->esElDiaExacto;
    }
}
