<?php

namespace App\Http\Middleware;

use App\Clinica\ContextoTrabajo;
use Closure;
use Illuminate\Http\Request;

/**
 * Sin contexto de trabajo no hay web clínica: si el usuario no eligió con qué médico/datos opera (o le
 * revocaron el acceso), se lo manda a elegirlo. Evita repetir la comprobación en cada controlador y que
 * una pantalla clínica se muestre con datos de otro `reg_medico`.
 *
 * **Con `clinica.contexto:opcional`** la pantalla también se abre **sin** contexto: sigue compartiéndolo
 * cuando existe, pero no manda a elegir consultorio cuando no (basta con que el parámetro esté en la ruta).
 * Es para la pantalla del **formato de impresión de un centro médico** (Fase 5 del cambio de `reg_medico`):
 * un centro no inicia sesión y el `Root` la abre desde el panel con `?reg_medico=<centro>`, así que puede
 * no tener contexto propio. La pantalla decide qué editar con `ResuelveElRegistroDelFormato`, y sus propias
 * rutas exigen `role:Root|Medico`: abrirla de más no da acceso a nada.
 */
class AsegurarContextoClinico
{
    public function handle(Request $request, Closure $next, ?string $opcional = null)
    {
        $contexto = app(ContextoTrabajo::class)->actual($request->user());

        if (! $contexto) {
            return $opcional === null ? redirect()->route('clinica.contexto') : $next($request);
        }

        // Los controladores y las vistas lo leen de acá sin volver a resolverlo.
        $request->attributes->set('contexto_clinico', $contexto);

        // El layout clínico los usa en **todas** sus pantallas, no solo en el shell: sin compartir
        // `modulos`, la navegación entre módulos desaparecería al entrar a Agenda o a Pacientes (el
        // menú salía de los módulos del shell y nada más).
        $trabajo = app(ContextoTrabajo::class);
        view()->share('contexto', $contexto);
        view()->share('modulos', $trabajo->modulos($contexto, $trabajo->roles($request->user())));

        return $next($request);
    }
}
