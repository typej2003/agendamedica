<?php

namespace App\Clinica\Consulta;

use App\Actions\Consulta\ConsultaAbierta;

/**
 * Cómo terminó un intento de **atender al paciente que acaba de quedar atendible** (O-05): la consulta que
 * quedó abierta —y de dónde salió la cita—, o el motivo por el que no se pudo abrir.
 *
 * El controlador lo traduce a una respuesta: a la consulta con su mensaje, o de vuelta a la ficha con el
 * aviso. La diferencia con un error de validación es que acá **no hay nada que corregir en un formulario**:
 * lo que falta es una condición del consultorio (el día no es laborable, el consultorio no tiene horario) y
 * la pantalla lo único que puede hacer es contarlo.
 */
final class ResultadoDeAtencion
{
    private function __construct(
        public readonly ?ConsultaAbierta $abierta = null,
        public readonly ?string $motivo = null,
        public readonly bool $citaCreada = false,
    ) {
    }

    /** Se abrió (o se reabrió) la consulta; `$citaCreada` dice si hubo que crearle la cita del día. */
    public static function abierta(ConsultaAbierta $abierta, bool $citaCreada): self
    {
        return new self($abierta, null, $citaCreada);
    }

    /** No se pudo atender, con el motivo ya redactado para la pantalla. */
    public static function noSePudo(string $motivo): self
    {
        return new self(null, $motivo);
    }

    public function abrio(): bool
    {
        return $this->abierta !== null;
    }

    public function consultaId(): ?int
    {
        return $this->abierta !== null ? (int) $this->abierta->consulta->id : null;
    }

    public function motivo(): string
    {
        return (string) $this->motivo;
    }

    /** El aviso de la redirección a la consulta: dice qué pasó con la cita del día. */
    public function mensaje(): string
    {
        if ($this->abierta === null) {
            return $this->motivo();
        }

        $consulta = 'La consulta N.º ' . $this->abierta->nroconsulta() . ' está abierta.';

        return $this->citaCreada
            ? 'Se le creó la cita de hoy en el consultorio y ' . lcfirst($consulta)
            : 'Se abrió la consulta de la cita de hoy. ' . $consulta;
    }
}
