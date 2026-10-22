<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Ciudades\CrearCiudad;
use App\Clinica\Ciudades\BuscadorDeCiudades;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Alta de una ciudad desde el selector con búsqueda (opción Crear). Sólo administradores (`role:Root` en la ruta).
 */
class CiudadController extends Controller
{
    public function crear(Request $request, CrearCiudad $crear)
    {
        $datos = $request->validate([
            'nombre'   => ['required', 'string', 'max:200'],
            'state_id' => ['required', 'integer'],
        ], [
            'nombre.required'   => 'Escribe el nombre de la ciudad.',
            'state_id.required' => 'Elige el estado de la ciudad.',
        ]);

        [$ciudad, $creada] = $crear->ejecutar($datos['nombre'], (int) $datos['state_id']);
        $ciudad->load('state');

        return response()->json([
            'value'  => $ciudad->id,
            'label'  => BuscadorDeCiudades::etiqueta($ciudad),
            'detail' => '',
            'creada' => $creada,
        ], $creada ? 201 : 200);
    }
}
