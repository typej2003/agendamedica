<?php

namespace App\Http\Controllers\Clinica;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Pantalla de entrada de la web clínica. Los módulos que muestra el menú salen de la especialidad del
 * contexto —los implementados y visibles para los roles del usuario— y los comparte el middleware
 * `AsegurarContextoClinico`, así que acá solo queda el estado del shell.
 */
class ShellController extends Controller
{
    public function index(Request $request)
    {
        $actual = $request->attributes->get('contexto_clinico');

        return view('clinica.shell', [
            'sinEspecialidad' => $actual['specialty'] === null,
        ]);
    }
}
