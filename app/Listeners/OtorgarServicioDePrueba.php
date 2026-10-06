<?php

namespace App\Listeners;

use App\Events\MedicoRegistrado;
use App\Services\ServicioService;

/**
 * Un médico nuevo recibe el plan por defecto gratis durante el mes de prueba. Si todavía no tiene
 * `reg_medico` (el panel viejo no siempre lo pide), no hay a qué colgarle el servicio y no se hace nada.
 */
class OtorgarServicioDePrueba
{
    public function __construct(private ServicioService $servicio)
    {
    }

    public function handle(MedicoRegistrado $evento): void
    {
        $reg = trim((string) $evento->medico->reg_medico);
        if ($reg !== '') {
            $this->servicio->otorgarPrueba($reg);
        }
    }
}
