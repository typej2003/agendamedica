<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;
use Illuminate\Session\TokenMismatchException;

/**
 * La verificación del token de la sesión (el `@csrf` de los formularios).
 *
 * **Lo único propio de la web clínica** (2026-10-19): cuando la sesión ya venció, un `POST` de `/clinica`
 * **no termina en la página del 419**. Laravel tira el `TokenMismatchException` antes de llegar al
 * `auth`, así que el médico —que seguía con la pantalla abierta— veía «Tu sesión venció. Vuelve a cargar la
 * página e inténtalo de nuevo» sobre una consulta a medio terminar, sin poder hacer nada: recargar no sirve
 * (la sesión no vuelve) y lo único que le quedaba era adivinar que había que ir a `/login`.
 *
 * Acá eso se traduce a lo que de verdad pasó —la sesión se venció— y a lo que hay que hacer: volver a
 * entrar. Sin cuerpo que enviar no hay nada que se pueda perder en el camino:
 *
 *  - **Formulario del navegador** (lo que ve el médico): `302` al login con el aviso, así el clic lo deja
 *    en la pantalla de entrar y no en un error. `redirect()->guest()` guarda además la página de origen
 *    como `url.intended`, que es lo que el login usa para seguir por donde iba.
 *  - **Petición de `fetch`** (el autoguardado de la consulta manda `X-Requested-With`, así que Laravel la ve
 *    como «espera JSON»): `419` con el mismo mensaje en JSON. Si en vez de eso se devolviera el `302`, el
 *    `fetch` lo seguiría y recibiría el HTML del login con un `200`: el autoguardado creería que guardó
 *    —el peor final posible para un texto clínico—.
 *
 * Fuera de `/clinica` no cambia nada: el panel de administración y el API siguen recibiendo su 419.
 */
class VerifyCsrfToken extends Middleware
{
    /**
     * The URIs that should be excluded from CSRF verification.
     *
     * @var array
     */
    protected $except = [
        //
    ];

    public function handle($request, Closure $next)
    {
        try {
            return parent::handle($request, $next);
        } catch (TokenMismatchException $excepcion) {
            if (! $request->is('clinica') && ! $request->is('clinica/*')) {
                throw $excepcion;
            }

            $aviso = 'Tu sesión venció por inactividad. Vuelve a entrar: lo que ya estaba guardado no se perdió.';

            if ($request->expectsJson()) {
                return response()->json(['message' => $aviso], 419);
            }

            return redirect()->guest(route('login'))->with('aviso', $aviso);
        }
    }
}
