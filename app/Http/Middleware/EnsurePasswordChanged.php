<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Mientras la cuenta tenga una clave temporal (`users.must_change_password`), lo único permitido es cambiarla.
 *
 * Por qué en el servidor y no solo en la pantalla del app: una clave temporal que se filtró no debe poder
 * bajar datos clínicos, y un cliente modificado no puede saltarse el paso. Las rutas que siguen abiertas
 * están en `PERMITIDAS`. La pantalla web de cambio es un formulario común, sin Livewire, a propósito: una
 * acción de Livewire no estaría permitida y quedaría redirigida.
 *
 *  - API: 403 con `{"code":"password_change_required"}` (el app lo usa para mostrar la pantalla).
 *  - Web: redirige a `/cambiar-password`.
 */
class EnsurePasswordChanged
{
    /** Rutas (por `path`) que siguen abiertas con clave temporal. */
    private const PERMITIDAS = [
        'api/app/cambiar-password',
        'api/user',
        'cambiar-password',
        'logout',
    ];

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user && $user->must_change_password && ! $request->is(self::PERMITIDAS)) {
            if ($request->expectsJson()) {
                return response()->json([
                    'code'    => 'password_change_required',
                    'message' => 'Debes cambiar tu contraseña temporal antes de continuar.',
                ], 403);
            }

            return redirect('/cambiar-password');
        }

        return $next($request);
    }
}
