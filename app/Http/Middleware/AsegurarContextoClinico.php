<?php

namespace App\Http\Middleware;

use App\Clinica\ContextoTrabajo;
use Closure;
use Illuminate\Http\Request;

/**
 * Sin contexto de trabajo no hay web clínica: si el usuario no eligió con qué médico/datos opera (o le
 * revocaron el acceso), se lo manda a elegirlo. Evita repetir la comprobación en cada controlador y que
 * una pantalla clínica se muestre con datos de otro `reg_medico`.
 */
class AsegurarContextoClinico
{
    public function handle(Request $request, Closure $next)
    {
        $contexto = app(ContextoTrabajo::class)->actual($request->user());

        if (! $contexto) {
            return redirect()->route('clinica.contexto');
        }

        // Los controladores y las vistas lo leen de acá sin volver a resolverlo.
        $request->attributes->set('contexto_clinico', $contexto);

        // El layout clínico los usa en **todas** sus pantallas, no solo en el shell: sin compartir
        // `modulos`, la navegación entre módulos desaparecería al entrar a Agenda o a Pacientes (el
        // menú salía de los módulos del shell y nada más).
        $trabajo = app(ContextoTrabajo::class);
        view()->share('contexto', $contexto);
        view()->share('modulos', $contexto['specialty']
            ? $contexto['specialty']->modulosVisibles($trabajo->roles($request->user()))
            : collect());

        return $next($request);
    }
}
