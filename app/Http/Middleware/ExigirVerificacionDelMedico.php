<?php

namespace App\Http\Middleware;

use App\Verificacion\AccesoDelMedico;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * **Corta el acceso del médico que la política de verificación no deja entrar** (2026-11-03).
 *
 * Es el mismo corte en las dos superficies:
 *  - **Web** (`/clinica`): cierra la sesión y vuelve al login con el motivo, como `EnsureAccountActive`.
 *  - **API** (el app): 403 con `{"code":"verificacion_pendiente"}`.
 *
 * La regla vive en `AccesoDelMedico`: administradores y cuentas sin ficha pasan; un carnet rechazado corta
 * siempre; con `exigir_carnet` encendido, un carnet sin verificar corta. No se aplica al grupo `web` entero
 * a propósito: un médico que además es paciente o administrador puede seguir entrando a su otra área.
 */
class ExigirVerificacionDelMedico
{
    public function __construct(private AccesoDelMedico $acceso)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        $motivo = $user ? $this->acceso->bloqueado($user) : null;

        if ($motivo === null) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'code' => 'verificacion_pendiente',
                'message' => $motivo,
            ], 403);
        }

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->withErrors(['email' => $motivo]);
    }
}
