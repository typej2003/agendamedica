<?php

namespace App\Http\Middleware;

use App\Clinica\ContextoAutomatico;
use App\Clinica\ContextoTrabajo;
use App\Services\ServicioService;
use Closure;
use Illuminate\Http\Request;

/**
 * Sin contexto de trabajo no hay web clínica: si el usuario no eligió con qué médico/datos opera (o le
 * revocaron el acceso), se lo manda a elegirlo. Evita repetir la comprobación en cada controlador y que
 * una pantalla clínica se muestre con datos de otro `reg_medico`.
 *
 * **`clinica.contexto:opcional`** deja que la pantalla se abra **sin** contexto: sigue compartiéndolo
 * cuando existe, pero no manda a elegir consultorio cuando no (basta con que el parámetro esté en la ruta).
 * Es para la pantalla del **formato de impresión de un centro médico** (Fase 5 del cambio de `reg_medico`):
 * un centro no inicia sesión y el `Root` la abre desde el panel con `?reg_medico=<centro>`, así que puede
 * no tener contexto propio. La pantalla decide qué editar con `ResuelveElRegistroDelFormato`, y sus propias
 * rutas exigen `role:Root|Medico`: abrirla de más no da acceso a nada.
 *
 * **`clinica.contexto:consultorios`** (2026-10-22) es para la pantalla **Sedes y consultorios**, que además
 * de las sedes muestra y carga **los consultorios del médico**: si no hay contexto, intenta resolverlo con
 * el único registro del usuario (`ContextoAutomatico::paraCargarUnConsultorio`) y lo deja fijado. Un médico
 * recién creado desde el panel —sin especialidad, sin consultorios y sin haber pasado por «Elegir
 * consultorio»— llegaba a esa pantalla sin contexto: no le ofrecía cargar nada y el menú lateral se quedaba
 * en «Inicio», sin manera de salir del paso. Con dos registros no se resuelve solo (hay que elegir en cuál)
 * y la pantalla se abre como antes, con el aviso que manda a elegir.
 */
class AsegurarContextoClinico
{
    /** El parámetro de `clinica.contexto` que resuelve solo el dueño de los consultorios cuando no hay contexto. */
    public const CONSULTORIOS = 'consultorios';

    public function __construct(private readonly ServicioService $servicios)
    {
    }

    public function handle(Request $request, Closure $next, ?string $opcional = null)
    {
        $trabajo = app(ContextoTrabajo::class);
        $contexto = $trabajo->actual($request->user());

        if ($contexto === null && $opcional === self::CONSULTORIOS) {
            $sinElegir = app(ContextoAutomatico::class)->paraCargarUnConsultorio($request->user());

            if ($sinElegir !== null) {
                $contexto = $trabajo->fijar(
                    $request->user(),
                    $sinElegir['reg_medico'],
                    $sinElegir['specialty_id'],
                );
            }
        }

        if (! $contexto) {
            return $opcional === null ? redirect()->route('clinica.contexto') : $next($request);
        }

        // Los controladores y las vistas lo leen de acá sin volver a resolverlo.
        $request->attributes->set('contexto_clinico', $contexto);

        // El layout clínico los usa en **todas** sus pantallas, no solo en el shell: sin compartir
        // `modulos`, la navegación entre módulos desaparecería al entrar a Agenda o a Pacientes (el
        // menú salía de los módulos del shell y nada más).
        view()->share('contexto', $contexto);
        view()->share('modulos', $trabajo->modulos($contexto, $trabajo->roles($request->user())));

        // El plan del contexto, para el banner del layout y para los botones de imprimir. Se comparte en
        // todas las pantallas clínicas: el aviso de “sin impresión” tiene que verse también fuera de la hoja.
        $estadoServicio = $this->servicios->estado((string) $contexto['reg_medico']);
        view()->share('estadoServicio', $estadoServicio);
        view()->share('planPermiteImprimir', (bool) ($estadoServicio['permite_imprimir'] ?? false));
        view()->share('motivoSinImpresion', ($estadoServicio['permite_imprimir'] ?? false)
            ? null
            : $this->servicios->motivoDeNoImprimir((string) $contexto['reg_medico'], $estadoServicio));

        return $next($request);
    }
}
