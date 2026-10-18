<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Catalogos\CrearPlantillaDeTexto;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * **«Guardar como plantilla» desde la consulta** (WEB-11.2): el médico escribió un texto y lo guarda para reusarlo. Es el alta del
 * catálogo de plantillas (`CrearPlantillaDeTexto`: correlativo de cuatro dígitos, título en mayúsculas ≤ 200, sin duplicar título
 * dentro del tipo); acá sólo se traduce el HTTP. Responde JSON porque el panel lo pide sin recargar la consulta (no se pierde lo
 * escrito en el campo).
 */
class PlantillaDeTextoController extends Controller
{
    public function guardar(Request $request, CrearPlantillaDeTexto $crear)
    {
        $contexto = $request->attributes->get('contexto_clinico');

        $datos = $request->validate([
            'tipo' => 'required|string|max:5',
            'titulo' => 'required|string|max:400',
            'descripcion' => 'required|string|max:20000',
        ]);

        try {
            $fila = $crear->ejecutar($contexto['reg_medico'], $datos);
        } catch (InvalidArgumentException $e) {
            return response()->json(['mensaje' => $e->getMessage()], 422);
        }

        return response()->json([
            'titulo' => trim((string) $fila->titulo),
            'texto' => trim(str_replace("\r\n", "\n", (string) $fila->descripcion)),
        ], 201);
    }
}
