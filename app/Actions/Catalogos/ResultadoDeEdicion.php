<?php

namespace App\Actions\Catalogos;

use Illuminate\Database\Eloquent\Model;

/**
 * Qué pasó al editar una fila de un catálogo clínico (WEB-8.1b): qué columnas se escribieron y cuáles no se pisaron
 * porque otra edición más nueva ya las había cambiado (el last-write-wins del sync, por columna). Es el mismo
 * resultado que `ResultadoDeMedicamento` (8.1a) para cualquier fila.
 */
final class ResultadoDeEdicion
{
    /**
     * @param  array<int,string>  $escritas  las columnas que se escribieron
     * @param  array<int,string>  $bloqueadas  las que tienen una edición más nueva y no se tocaron
     */
    public function __construct(
        public readonly Model $fila,
        public readonly array $escritas,
        public readonly array $bloqueadas,
    ) {
    }

    /** No había nada que escribir: lo que llegó es lo que ya estaba. */
    public function sinCambios(): bool
    {
        return $this->escritas === [] && $this->bloqueadas === [];
    }

    /** Alguna columna no se pisó porque había una edición más nueva (de otro equipo). */
    public function huboConflicto(): bool
    {
        return $this->bloqueadas !== [];
    }
}
