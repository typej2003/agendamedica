<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Consulta\GuardarTextosDeLosInformes;
use App\Clinica\Consulta\Cardiologia\TextosDeLosInformes;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * **Los textos por defecto de los informes del cardiólogo** (C12, 2026-11-07): la pantalla de «Configuración» donde el
 * cardiólogo escribe el texto con el que arranca el Doppler de miembros superiores, el protocolo del marcapasos y su
 * texto de marca.
 */
class TextosDeLosInformesController extends Controller
{
    public function editar(Request $request, TextosDeLosInformes $textos)
    {
        $contexto = $request->attributes->get('contexto_clinico');

        return view('clinica.cardio-textos.index', [
            'fila' => $textos->delMedico((string) $contexto['reg_medico']),
        ]);
    }

    public function guardar(Request $request, GuardarTextosDeLosInformes $guardar)
    {
        $contexto = $request->attributes->get('contexto_clinico');

        $datos = $request->validate([
            'duplex_superior' => ['nullable', 'string', 'max:5000'],
            'protocolo_marcapaso' => ['nullable', 'string', 'max:5000'],
            'texto_marca' => ['nullable', 'string', 'max:5000'],
        ]);

        $guardar->ejecutar((string) $contexto['reg_medico'], $datos);

        return redirect()
            ->route('clinica.configuracion.textos-cardiacos')
            ->with('estado', 'Textos guardados.');
    }
}
