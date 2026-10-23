<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * La clave del **canal de publicación del escritorio**: la que autoriza publicar una versión nueva.
 *
 * No se usa para descargar —el consultorio baja sin credencial, como hoy con el paquete— y no es la
 * del sync: es una clave de servicio, y se rota con `php artisan escritorio:canal-clave --rotar`.
 */
class ClaveDelCanalEscritorio
{
    public function handle(Request $request, Closure $next)
    {
        $esperada = (string) config('escritorio.clave');
        $dada = (string) $request->header('X-API-KEY');

        abort_if($esperada === '', 503, 'Falta ESCRITORIO_CANAL_KEY en el servidor.');

        abort_if($dada === '' || ! hash_equals($esperada, $dada), 401, 'API key inválida.');

        return $next($request);
    }
}
