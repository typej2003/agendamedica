<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un escritorio PowerBuilder habló con el API (estado, carga inicial o cambios) en nombre de `$regMedico`.
 * Quien sincroniza desde el escritorio recibe el año de cortesía: lo escucha App\Listeners\OtorgarAnioPowerBuilder.
 */
class EscritorioSincronizo
{
    use Dispatchable;

    public function __construct(public string $regMedico)
    {
    }
}
