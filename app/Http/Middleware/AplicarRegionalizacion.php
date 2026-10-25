<?php

namespace App\Http\Middleware;

use App\Support\Regionalizacion;
use Closure;
use Illuminate\Http\Request;

/**
 * Deja en el contenedor la regionalización del dispositivo (WEB-13.5) para este request: la de la cookie
 * `ddr_region` si es válida, o la del consultorio. La cookie la escribe `public/js/regionalizacion.js` y no
 * va cifrada (`EncryptCookies::$except`): es un dato de presentación, sin nada que proteger, y lo que no
 * sea una de las combinaciones válidas se descarta.
 */
class AplicarRegionalizacion
{
    public function handle(Request $request, Closure $next)
    {
        $cookie = $request->cookies->get(Regionalizacion::COOKIE);

        app()->instance(
            Regionalizacion::class,
            Regionalizacion::desdeTexto(is_string($cookie) ? $cookie : null) ?? Regionalizacion::porDefecto(),
        );

        return $next($request);
    }
}
