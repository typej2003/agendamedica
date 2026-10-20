<?php

namespace App\Http\Middleware;

use App\Events\ActividadWebRegistrada;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * Anota la última actividad web de la cuenta **sin escribir en la base desde el request**: dispara
 * `ActividadWebRegistrada`, cuyo listener corre en la cola.
 *
 * Además **se limita a un aviso por cuenta cada `INTERVALO` segundos** con un `Cache::add` (atómico en
 * redis/memcached/database; el `file` del entorno local alcanza): sin eso, cada request encolaría un job, y con
 * `QUEUE_CONNECTION=database` encolar ya es un `INSERT`. La columna queda con esa resolución, que sobra para
 * "hace 2 horas".
 *
 * Corre después de `$next` para ver también la sesión que se abre en ese request (el login) y para no contar
 * la que se cierra. El latido de la sesión (`clinica.sesion`) no es actividad: lo manda el JS con la pestaña
 * a la vista aunque nadie la use.
 */
class RegistrarActividadWeb
{
    public const INTERVALO = 300;

    private const SIN_ACTIVIDAD = ['clinica.sesion'];

    public function handle(Request $request, Closure $next)
    {
        $respuesta = $next($request);

        $id = Auth::id();
        if ($id !== null
            && ! $request->routeIs(...self::SIN_ACTIVIDAD)
            && Cache::add("actividad-web:{$id}", true, self::INTERVALO)) {
            ActividadWebRegistrada::dispatch((int) $id, now()->toDateTimeString());
        }

        return $respuesta;
    }
}
