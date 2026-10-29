<?php

namespace App\Http\Middleware;

use App\Services\ServicioService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * **Sin plan vigente no se imprime** (2026-10-28): las hojas de impresión y los PDF de la web clínica
 * responden con un aviso y mandan a renovar.
 *
 * Se aplica sólo a las rutas de impresión, no a las pantallas de trabajo. El plan que manda es el del
 * `reg_medico` del contexto (el consultorio con el que se está trabajando), no el de un registro cualquiera
 * del médico. La gracia imprime igual que sincroniza; el corte es al pasar a `vencido`, cuando no hay plan
 * o cuando el plan contratado no incluye `impresion_reportes`.
 *
 * Si la ruta se abrió sin contexto (el formato de un centro que un Root abre desde el panel), se deja pasar:
 * es una pantalla de administración y no hay `reg_medico` contra el que decidir.
 */
class ExigirPlanParaImprimir
{
    public function __construct(private readonly ServicioService $servicios)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $regMedico = is_array($contexto) ? trim((string) ($contexto['reg_medico'] ?? '')) : '';

        if ($regMedico === '') {
            return $next($request);
        }

        $estado = $this->servicios->estado($regMedico);
        if (($estado['permite_imprimir'] ?? false) === true) {
            return $next($request);
        }

        $mensaje = (string) $this->servicios->motivoDeNoImprimir($regMedico, $estado);
        $usuario = $request->user();

        if ($usuario !== null && ! $usuario->trabajaLaConsulta()) {
            return redirect()->route('clinica.inicio')
                ->with('error', $mensaje . ' Pídele al médico que renueve el plan.');
        }

        return redirect()->route('clinica.plan')->with('error', $mensaje);
    }
}
