<?php

namespace App\Http\Middleware;

use App\Paciente\BloqueoDePacientes;
use App\Paciente\NivelesDeIdentidad;
use Closure;
use Illuminate\Http\Request;

/**
 * Antes de que un paciente-cuenta reserve o reprograme por «Mis citas»:
 *
 *  1. Si está bloqueado (su cuenta, su teléfono validado o su cédula confirmada) no sigue.
 *  2. Si la validación de su teléfono venció (`identidad.telefono_validacion_meses`) o nunca existió, debe
 *     confirmarlo o cambiarlo con un código antes de reservar; vuelve a donde iba al terminar.
 */
class ExigirPacienteHabilitado
{
    public function __construct(private BloqueoDePacientes $bloqueo, private NivelesDeIdentidad $niveles)
    {
    }

    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        if ($this->bloqueo->estaBloqueado($user)) {
            return redirect()->route('paciente.citas')->with('error', BloqueoDePacientes::AVISO);
        }

        if (! $this->niveles->telefonoVigente($user)) {
            if ($request->isMethod('GET')) {
                $request->session()->put('telefono.volver', $request->fullUrl());
            }

            return redirect()
                ->route('paciente.telefono')
                ->with('error', 'Para reservar, confirma tu teléfono con un código (o cámbialo si es otro).');
        }

        return $next($request);
    }
}
