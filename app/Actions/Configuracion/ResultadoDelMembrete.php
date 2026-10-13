<?php

namespace App\Actions\Configuracion;

use App\Models\Evolucion;

/**
 * Lo que pasó al guardar el membrete (`evolucion`): la fila, qué columnas se escribieron y cuáles **no** porque otro
 * equipo (el escritorio o el móvil) las editó después (last-write-wins por columna, `RegistroDeCambios`).
 *
 * Los dos casos "no cambió nada" y "una edición más nueva me pisa" son distintos y la pantalla los dice distinto.
 */
final class ResultadoDelMembrete
{
    /**
     * @param  array<int,string>  $escritas
     * @param  array<int,string>  $bloqueadas
     */
    public function __construct(
        public readonly Evolucion $evolucion,
        public readonly array $escritas,
        public readonly array $bloqueadas,
    ) {
    }

    public function huboCambios(): bool
    {
        return $this->escritas !== [];
    }

    public function huboBloqueo(): bool
    {
        return $this->bloqueadas !== [];
    }
}
