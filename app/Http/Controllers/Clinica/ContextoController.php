<?php

namespace App\Http\Controllers\Clinica;

use App\Clinica\ContextoTrabajo;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Elegir el contexto de trabajo de la web clínica: con qué médico/registro, especialidad y sede se opera.
 */
class ContextoController extends Controller
{
    private ContextoTrabajo $contexto;

    public function __construct(ContextoTrabajo $contexto)
    {
        $this->contexto = $contexto;
    }

    public function editar(Request $request)
    {
        return view('clinica.contexto', [
            'disponibles' => $this->contexto->disponibles($request->user()),
            'actual'      => $this->contexto->actual($request->user()),
        ]);
    }

    public function guardar(Request $request)
    {
        $datos = $request->validate([
            'reg_medico'   => ['required', 'string'],
            'specialty_id' => ['nullable', 'integer'],
            'office_id'    => ['nullable', 'integer'],
        ]);

        $this->contexto->fijar(
            $request->user(),
            $datos['reg_medico'],
            $datos['specialty_id'] ?? null,
            $datos['office_id'] ?? null
        );

        return redirect()->route('clinica.inicio')->with('estado', 'Listo: ya estás trabajando en ese consultorio.');
    }

    public function salir(Request $request)
    {
        $this->contexto->limpiar();

        return redirect()->route('clinica.contexto');
    }
}
