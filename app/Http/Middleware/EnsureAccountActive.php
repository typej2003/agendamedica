<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Corta el acceso de una cuenta bloqueada (`users.is_active = false`).
 *
 *  - API (JSON): 403 con `{"code":"account_blocked"}`. Bloquear además borra sus tokens, así que esto cubre
 *    el caso de un token emitido justo antes del bloqueo.
 *  - Web: cierra la sesión y vuelve al login con el motivo.
 *
 * No afecta al sync del escritorio: ese usa su propia credencial (`SyncAuthService`), a propósito, para no
 * perder datos clínicos por un bloqueo.
 */
class EnsureAccountActive
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user && $user->is_active === false) {
            if ($request->expectsJson()) {
                return response()->json([
                    'code'    => 'account_blocked',
                    'message' => 'Tu cuenta está bloqueada. Comunícate con soporte.',
                ], 403);
            }

            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'email' => 'Tu cuenta está bloqueada. Comunícate con soporte.',
            ]);
        }

        return $next($request);
    }
}
