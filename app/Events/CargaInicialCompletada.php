<?php

namespace App\Events;

use App\Models\SyncCarga;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * La **carga inicial del escritorio** quedó completa (Fase 1 del sync del legado).
 *
 * Lo escucha `App\Listeners\ReconstruirIndiceDePacientesTrasLaCarga`: la carga escribe miles de
 * pacientes sin indexarlos uno por uno (PecleSearch), así que al terminar hay que rehacer el índice
 * del buscador. Se dispara **una sola vez por carga**, en la transición a `COMPLETA`.
 */
class CargaInicialCompletada
{
    use Dispatchable;

    public function __construct(public SyncCarga $carga)
    {
    }
}
