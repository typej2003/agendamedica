<?php

namespace App\Http\View\Composers;

use App\Clinica\Consulta\VolverALaConsulta;
use App\Clinica\ContextoAutomatico;
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
        $usuario = $peticion->user();
        $contexto = $compartidos['contexto'] ?? null;

        // ¿Queda algo que elegir en la pantalla de consultorio? (2026-10-19). Si no queda nada, esa pantalla
        // se salta, así que la barra no ofrece un «Cambiar consultorio» que rebotaría a la misma pantalla. Se
        // pregunta sólo cuando hay contexto elegido: en las pantallas sin contexto el acceso se deja (desde
        // sedes, por ejemplo, sirve para entrar al consultorio).
        $hayAlgoQueElegir = true;
        if ($contexto !== null && $usuario !== null) {
            $hayAlgoQueElegir = app(ContextoAutomatico::class)->de($usuario) === null;
        }

        // El botón «Volver a la consulta» de las pantallas del paciente abiertas desde una consulta (`VolverALaConsulta`).
        $vista->with('volverALaConsulta', VolverALaConsulta::para($peticion));

        $vista->with('armazon', new ArmazonVista(
            $contexto,
            $compartidos['modulos'] ?? new Collection(),
            (bool) $usuario?->esAdministrador(),
            $peticion->route()?->getName(),
            (bool) $usuario?->trabajaLaConsulta(),
            // El listado clínico elegido (WEB-6.8): «Listados» es un grupo y sus tres hijos comparten ruta, así que
            // el activo sale de la query. En cualquier otra pantalla el valor no se usa.
            $peticion->query('listado') !== null ? (string) $peticion->query('listado') : null,
            // La consulta abierta (revisión de Alexander): el controlador del shell la comparte con `id` y `seccion`;
            // el armazon la usa para el grupo «Consulta» del menú lateral y nada más.
            is_array($compartidos['consulta_en_curso'] ?? null) ? $compartidos['consulta_en_curso'] : null,
            $hayAlgoQueElegir,
        ));
    }
}
