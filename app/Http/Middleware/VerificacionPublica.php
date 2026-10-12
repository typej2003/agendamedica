<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * **Las cabeceras de la página pública de verificación del récipe** (WEB-5.6).
 *
 * Esa página la abre quien escanea el QR de una hoja (una farmacia), sin sesión, y el token de su dirección es una capacidad:
 *
 *  - `X-Robots-Tag: noindex`: que ningún buscador la indexe ni guarde copia.
 *  - `Referrer-Policy: no-referrer`: el token no viaja a ningún sitio al que se salga desde la página.
 *  - `Cache-Control: no-store`: lo que muestra es el récipe **vivo** y no debe quedar en una caché.
 *  - `Content-Security-Policy` cerrada: sólo estilos y fuentes propios, sin scripts, sin incrustarla en otro sitio.
 *
 * Va en un grupo propio (`verificacion`) que **no** pasa por el grupo `web`: sin sesión ni cookies.
 */
class VerificacionPublica
{
    public function handle(Request $request, Closure $next)
    {
        $respuesta = $next($request);

        $respuesta->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $respuesta->headers->set('Referrer-Policy', 'no-referrer');
        $respuesta->headers->set('Cache-Control', 'no-store, private');
        $respuesta->headers->set('X-Content-Type-Options', 'nosniff');
        $respuesta->headers->set(
            'Content-Security-Policy',
            "default-src 'none'; style-src 'self'; font-src 'self'; img-src 'self' data:; form-action 'self'; base-uri 'none'; frame-ancestors 'none'",
        );

        return $respuesta;
    }
}
