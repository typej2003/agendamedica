<?php

namespace App\Actions\Consulta;

/**
 * Qué pasó al guardar una solicitud de la consulta (constancia, reposo, referencia o informe, WEB-5.3a): si se
 * **creó** la fila, qué **columnas** se escribieron, cuáles el sync **no dejó pisar** porque hay una edición más nueva
 * y si la fila se **eliminó**. Con esto la pantalla cuenta lo que pasó en lugar de decir «Guardado» a ciegas.
 */
final class ResultadoDeSolicitud
{
    /**
     * @param  array<int,string>  $columnas  las columnas que se escribieron en una fila que ya existía
     * @param  array<int,string>  $bloqueadas  las que no se escribieron porque hay una edición más nueva en `sync_changes`
     */
    public function __construct(
        public readonly bool $creado = false,
        public readonly array $columnas = [],
        public readonly array $bloqueadas = [],
        public readonly bool $eliminado = false,
    ) {
    }

    /** ¿Hay una edición más nueva (del escritorio o del teléfono) que impidió escribir algo? */
    public function huboConflicto(): bool
    {
        return $this->bloqueadas !== [];
    }

    /** ¿No se escribió nada y no hubo conflicto? (guardar lo que ya estaba guardado) */
    public function sinCambios(): bool
    {
        return ! $this->creado && ! $this->eliminado && $this->columnas === [] && $this->bloqueadas === [];
    }

    /** ¿Quedó algo escrito (se creó, se eliminó o cambió alguna columna)? */
    public function seEscribio(): bool
    {
        return $this->creado || $this->eliminado || $this->columnas !== [];
    }
}
