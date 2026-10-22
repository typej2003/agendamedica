<?php

namespace App\Http\Controllers\Clinica;

use App\Clinica\Ciudades\BuscadorDeCiudades;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Búsqueda de ciudades para `<x-ui.selector-buscable>` (JSON `{resultados: [{value, label, detail}]}`).
 *
 * Dos rutas, una lógica: la autenticada (`/clinica/ciudades/buscar`, para el admin y la web clínica) y la pública del
 * registro de médicos (`/registro/medico/ciudades`, sin sesión y con límite de peticiones). La pública no devuelve
 * ids, sólo el nombre y el estado: es lo que el formulario guarda como texto.
 */
class CiudadBusquedaController extends Controller
{
    public function buscar(Request $request, BuscadorDeCiudades $buscador)
    {
        return $this->responder($request, $buscador, $request->query('valor') === 'nombre' ? 'nombre' : 'id');
    }

    public function buscarPublico(Request $request, BuscadorDeCiudades $buscador)
    {
        return $this->responder($request, $buscador, 'nombre');
    }

    private function responder(Request $request, BuscadorDeCiudades $buscador, string $valor)
    {
        $datos = $request->validate([
            'q'      => ['nullable', 'string', 'max:100'],
            'limite' => ['nullable', 'integer', 'min:1'],
        ]);

        return response()->json(['resultados' => $buscador->buscar(
            (string) ($datos['q'] ?? ''),
            (int) ($datos['limite'] ?? 20),
            $valor
        )]);
    }
}
