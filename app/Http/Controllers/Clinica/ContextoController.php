<?php

namespace App\Http\Controllers\Clinica;

use App\Clinica\ContextoAutomatico;
use App\Clinica\ContextoTrabajo;
use App\Clinica\Vistas\ContextoVista;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Elegir el contexto de trabajo de la web clínica: con qué médico/registro, especialidad y sede se opera.
 *
 * **Cuando no hay nada que elegir la pantalla se salta** (decisión del usuario, 2026-10-19): un solo médico
 * con una sola especialidad y uno o ningún consultorio entra directo al consultorio, sin pasar por acá. La
 * regla y su porqué están en `ContextoAutomatico`.
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
        $automatico = app(ContextoAutomatico::class)->de($request->user());

        if ($automatico !== null) {
            $this->contexto->fijar(
                $request->user(),
                $automatico['reg_medico'],
                $automatico['specialty_id'],
                $automatico['office_id']
            );

            $destino = redirect()->route('clinica.inicio');

            // Sin consultorios cargados no hay nada que elegir, pero sí algo que hacer: la agenda no puede
            // armar la jornada. Es el mismo aviso que daba la pantalla que ya no se ve.
            return $automatico['office_id'] === null
                ? $destino->with('avisos', ['Todavía no tienes consultorios cargados: cárgalos en «Sedes y consultorios» para que la agenda pueda armar la jornada.'])
                : $destino;
        }

        return view('clinica.contexto', [
            'vm' => new ContextoVista($this->contexto->disponibles($request->user())),
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
