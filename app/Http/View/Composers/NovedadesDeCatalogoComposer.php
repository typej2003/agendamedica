<?php

namespace App\Http\View\Composers;

use App\Actions\Catalogos\CopiarCatalogoInicial;
use App\Clinica\Catalogos\CatalogosDisponibles;
use App\Clinica\Catalogos\Iniciales\CatalogosIniciales;
use App\Clinica\Catalogos\Vistas\NovedadesDeCatalogoVista;
use App\Models\MedicoRegistro;
use Illuminate\View\View;

/**
 * Le da a las pantallas de `clinica/catalogos/*` su `$novedadesIniciales` (WEB-12.8): la tarjeta «Cargar el catálogo inicial» con lo que el catálogo inicial tiene y el
 * médico todavía no. Sale de la ruta (el catálogo) y del contexto de trabajo; los controladores de cada catálogo no se tocan. Sin novedades, o en una pantalla de un
 * catálogo sin versión inicial, el valor es `null` y la vista no pinta nada.
 */
class NovedadesDeCatalogoComposer
{
    public function compose(View $vista): void
    {
        $peticion = request();
        $slug = CatalogosDisponibles::slugDeLaRuta($peticion->route()?->getName());
        $contexto = $peticion->attributes->get('contexto_clinico');

        if ($slug === null || ! CatalogosIniciales::existe($slug) || ! is_array($contexto) || empty($contexto['reg_medico'])) {
            $vista->with('novedadesIniciales', null);

            return;
        }

        $def = CatalogosIniciales::de($slug);
        $registros = $this->registros($contexto);
        $novedades = app(CopiarCatalogoInicial::class)->novedades($slug, $registros);

        $vista->with('novedadesIniciales', $novedades->isEmpty() ? null : new NovedadesDeCatalogoVista(
            $def['titulo'], $def['singular'], $def['etiqueta'], $novedades, route('clinica.catalogos.cargar-inicial', $slug),
        ));
    }

    /** @return array<int,string> los `reg_medico` del médico, igual que el resto de los catálogos */
    private function registros(array $contexto): array
    {
        $medico = $contexto['medico'] ?? null;
        $registros = $medico ? MedicoRegistro::where('medico_id', $medico->id)->pluck('reg_medico')->filter()->all() : [];
        $registros[] = (string) $contexto['reg_medico'];

        return array_values(array_unique($registros));
    }
}
