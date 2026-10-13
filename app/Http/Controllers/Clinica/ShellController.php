<?php

namespace App\Http\Controllers\Clinica;

use App\Clinica\FechaDelConsultorio;
use App\Clinica\Vistas\InicioVista;
use App\Cumpleanos\CumpleanosDeHoy;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Pantalla de entrada de la web clínica. Los módulos que muestra salen de la especialidad del contexto
 * —los implementados y visibles para los roles del usuario— y los comparte el middleware
 * `AsegurarContextoClinico`.
 */
class ShellController extends Controller
{
    public function index(Request $request, CumpleanosDeHoy $cumpleanos)
    {
        $actual = $request->attributes->get('contexto_clinico');

        // El aviso de cumpleaños del día (WEB-2.9) es solo un contador: la pantalla de la que cuelga la
        // acción no es un módulo del menú (es una herramienta, y provisional hasta WEB-8.2), así que
        // Inicio es lo único que la pone delante del médico.
        $cuantos = $cumpleanos->delMedico($actual['reg_medico'], FechaDelConsultorio::hoy())->count();

        return view('clinica.shell', [
            'vm' => new InicioVista($actual, view()->shared('modulos', collect()), $cuantos),
        ]);
    }
}
