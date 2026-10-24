<?php

namespace App\Jobs;

use App\Clinica\Pacientes\CorrectorDeLaBusqueda;
use Illuminate\Foundation\Bus\Dispatchable;
use Pecle\Search\Laravel\PecleSearchManager;
use Throwable;

/**
 * Rehace el **índice del buscador de pacientes** (PecleSearch) después de una carga masiva.
 *
 * Se despacha con `dispatchAfterResponse()` (ver `App\Listeners\ReconstruirIndiceDePacientesTrasLaCarga`):
 * corre al terminar la respuesta, así el escritorio no espera. No es un job de cola: no depende de que
 * haya un worker, nada más de que el request termine (con PHP-FPM, después de `fastcgi_finish_request`).
 *
 * Reconstruir —y no reindexar fila por fila— es a propósito: la carga no pasó por el `saved` de cada
 * paciente (`CargaInicialService` lo apaga), así que el índice quedó sin esas palabras y el
 * vocabulario del corrector, sin ellas. Un `rebuild` deja las dos cosas como el comando.
 */
final class ReconstruirIndiceDePacientes
{
    use Dispatchable;

    public function handle(PecleSearchManager $buscador): void
    {
        // Si el proyecto dejó de declarar el índice, no hay nada que reconstruir.
        if (! in_array(CorrectorDeLaBusqueda::INDICE, $buscador->indexes(), true)) {
            return;
        }

        try {
            $buscador->rebuild(CorrectorDeLaBusqueda::INDICE);
        } catch (Throwable $e) {
            // El buscador sigue funcionando sin el índice (busca por LIKE); esto es una mejora.
            report($e);
        }
    }
}
