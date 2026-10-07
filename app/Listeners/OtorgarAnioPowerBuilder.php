<?php

namespace App\Listeners;

use App\Events\EscritorioSincronizo;
use App\Services\ServicioService;

/**
 * Un año gratis a todo médico que sincroniza desde el escritorio (una sola vez por `reg_medico`).
 * Se dispara en cada contacto del escritorio, que es frecuente (cada 15 s): `otorgarPowerBuilder` es una
 * consulta barata cuando ya lo tiene.
 */
class OtorgarAnioPowerBuilder
{
    public function __construct(private ServicioService $servicio)
    {
    }

    public function handle(EscritorioSincronizo $evento): void
    {
        $this->servicio->otorgarPowerBuilder($evento->regMedico);
    }
}
