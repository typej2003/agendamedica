<?php

namespace App\Listeners;

use App\Events\CargaInicialCompletada;
use App\Jobs\ReconstruirIndiceDePacientes;

/**
 * Al terminar la **carga inicial del escritorio**, deja el índice del buscador al día **en segundo
 * plano**: `CargaInicialService` apaga los eventos de `Paciente` para no pagar el vocabulario del
 * corrector por cada una de las miles de filas que sube, y acá se rehace una sola vez.
 *
 * `dispatchAfterResponse()` corre el trabajo cuando la respuesta ya salió (con PHP-FPM, después de
 * `fastcgi_finish_request`): el escritorio recibe su "carga completa" sin esperar la reconstrucción.
 * Si el servidor no puede cerrar la conexión antes, el trabajo corre igual al final del request.
 */
class ReconstruirIndiceDePacientesTrasLaCarga
{
    public function handle(CargaInicialCompletada $evento): void
    {
        ReconstruirIndiceDePacientes::dispatchAfterResponse();
    }
}
