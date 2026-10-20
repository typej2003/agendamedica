<?php

namespace App\Http\View\Composers;

use App\Clinica\Guia\PasosEsenciales;
use Illuminate\View\View;

/**
 * Le da a `clinica/layout` el siguiente paso esencial pendiente del médico (`$pasoEsencial`) para el banner y el
 * diálogo guiado. Sólo para quien trabaja la consulta; la secretaría no ve nada. Si la pantalla actual **es** el
 * destino del paso, no se repite el aviso encima de lo que ya está haciendo.
 */
class PasoEsencialComposer
{
    public function __construct(private PasosEsenciales $pasos)
    {
    }

    public function compose(View $vista): void
    {
        $usuario = request()->user();
        $paso = null;

        if ($usuario?->trabajaLaConsulta()) {
            $contexto = $vista->getFactory()->getShared()['contexto'] ?? null;
            $medico = $contexto['medico'] ?? $usuario->medico;

            $paso = $medico ? $this->pasos->siguiente($medico) : null;

            if ($paso !== null && request()->route()?->getName() === $paso->ruta) {
                $paso = null;
            }
        }

        $vista->with('pasoEsencial', $paso);
    }
}
