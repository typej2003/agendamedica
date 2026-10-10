<?php

namespace App\Http\Controllers\Clinica;

use App\Clinica\Vistas\InicioVista;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Pantalla de entrada de la web clínica. Los módulos que muestra salen de la especialidad del contexto
 * —los implementados y visibles para los roles del usuario— y los comparte el middleware
 * `AsegurarContextoClinico`.
 */
class ShellController extends Controller
{
    public function index(Request $request)
    {
        $actual = $request->attributes->get('contexto_clinico');

        return view('clinica.shell', [
            'vm' => new InicioVista($actual, view()->shared('modulos', collect())),
        ]);
    }
}
