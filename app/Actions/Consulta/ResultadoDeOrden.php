<?php

namespace App\Actions\Consulta;

/**
 * Qué pasó al guardar una **orden de varias filas** (la orden de laboratorio y la solicitud de estudios, WEB-5.4): cuántos exámenes o
 * estudios se **agregaron** y cuántos se **quitaron**, si cambió el texto de la observación y si el sync no dejó pisar algo porque había una
 * edición más nueva. Con esto la pantalla cuenta lo que pasó en lugar de decir «Guardado» a ciegas.
 */
final class ResultadoDeOrden
{
    /**
     * @param  array<int,string>  $columnas  las columnas que se escribieron en filas que ya existían (la observación, el orden)
     * @param  array<int,string>  $bloqueadas  las que no se escribieron porque hay una edición más nueva en `sync_changes`
     */
    public function __construct(
        public readonly int $agregados = 0,
        public readonly int $quitados = 0,
        public readonly bool $observacionCreada = false,
        public readonly array $columnas = [],
        public readonly array $bloqueadas = [],
    ) {
    }

    /** ¿Hay una edición más nueva (del escritorio o del teléfono) que impidió escribir algo? */
    public function huboConflicto(): bool
    {
        return $this->bloqueadas !== [];
    }

    /** ¿Quedó algo escrito? */
    public function seEscribio(): bool
    {
        return $this->agregados > 0 || $this->quitados > 0 || $this->observacionCreada || $this->columnas !== [];
    }

    /** ¿No se escribió nada y no hubo conflicto? (guardar lo que ya estaba guardado) */
    public function sinCambios(): bool
    {
        return ! $this->seEscribio() && ! $this->huboConflicto();
    }
}
