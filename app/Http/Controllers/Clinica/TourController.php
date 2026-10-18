<?php

namespace App\Http\Controllers\Clinica;

use App\Clinica\Tours\RegistroDeTours;
use App\Clinica\Tours\ToursDisponibles;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * **La marca de un tour visto** (A-15): lo llama el JS cuando el usuario termina o salta el tour, o cuando
 * cierra con `Esc` (que equivale a saltarlo).
 *
 * Es un `POST` a propósito: una petición de lectura (`GET`) no puede escribir. Marcar es idempotente, así
 * que reabrir el tour con el botón y volver a terminarlo no duplica la fila ni borra la marca anterior.
 *
 * `pantalla` tiene que ser una de `ToursDisponibles` y el usuario tiene que poder verla (la secretaría no
 * ve el tour de la consulta ni el de los catálogos): si no, 404 / 403, como sus pantallas.
 */
class TourController extends Controller
{
    public function marcar(Request $request, string $pantalla, RegistroDeTours $registro)
    {
        abort_unless(ToursDisponibles::existe($pantalla), 404);
        abort_unless(ToursDisponibles::visiblePara($pantalla, $request->user()), 403);

        $marca = $registro->marcarVisto($request->user()->id, $pantalla);

        return response()->json([
            'pantalla'  => $pantalla,
            'visto_el'  => $marca->visto_el?->toIso8601String(),
        ]);
    }
}
