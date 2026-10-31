<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * **Las cabeceras del link público de pago `/pagar/{token}`**.
 *
 * El link viaja por WhatsApp y lo puede abrir cualquiera, así que la página no se indexa, no deja
 * escapar el token en el `Referer` y no se guarda en ninguna caché:
 *
 *  - `X-Robots-Tag: noindex, nofollow`: ningún buscador la indexa ni sigue sus enlaces.
 *  - `Referrer-Policy: no-referrer`: el token no viaja a ningún sitio al que se salga desde la página.
 *  - `Cache-Control: no-store`: lo que muestra es el estado vivo del pago y no debe quedar en una caché.
 *  - `X-Content-Type-Options: nosniff`: no se adivina el tipo del contenido.
 *
 * No reusa el middleware de la verificación del récipe porque aquél cierra la CSP sin scripts ni CDN y
 * esta pantalla es parte del mismo producto que el portal de reservas (Bootstrap, Livewire e iconos).
 */
class PagoPublico
{
    public function handle(Request $request, Closure $next)
    {
        $respuesta = $next($request);

        $respuesta->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $respuesta->headers->set('Referrer-Policy', 'no-referrer');
        $respuesta->headers->set('Cache-Control', 'no-store');
        $respuesta->headers->set('X-Content-Type-Options', 'nosniff');

        return $respuesta;
    }
}
