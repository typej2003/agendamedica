<?php

namespace App\Http\View\Composers;

use App\Clinica\Vistas\ArmazonVista;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Le da a `clinica/layout` su ViewModel (`$armazon`). El contexto de trabajo y los módulos los comparte el
 * middleware `AsegurarContextoClinico` cuando existe contexto; las pantallas que no lo tienen (sedes, elegir
 * consultorio) reciben un armazón sin médico ni módulos.
 */
class ArmazonClinicaComposer
{
    public function compose(View $vista): void
    {
        $compartidos = $vista->getFactory()->getShared();
        $peticion = request();

        $vista->with('armazon', new ArmazonVista(
            $compartidos['contexto'] ?? null,
            $compartidos['modulos'] ?? new Collection(),
            (bool) $peticion->user()?->esAdministrador(),
            $peticion->route()?->getName(),
        ));
    }
}
