<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Consulta\RegistrarSesionDeConsulta;
use App\Clinica\Consulta\ConsultasDelContexto;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * **El candado suave de la consulta** (`consulta_sesiones`): el `POST` ligero con el que el navegador registra o
 * renueva «esta consulta está abierta en mi dispositivo». Responde JSON `{estado: ok|conflicto, ...}`.
 *
 * Misma autorización que el resto de la consulta: la ruta es sólo del médico (`role:Root|Medico`) y la consulta se
 * resuelve contra el contexto (`ConsultasDelContexto`), así que una consulta de otro consultorio es **404**. La
 * regla vive en `App\Actions\Consulta\RegistrarSesionDeConsulta`; acá sólo se traduce el HTTP.
 */
class ConsultaSesionController extends Controller
{
    use ResuelveLosRegistrosDelMedico;

    public function __construct(private ConsultasDelContexto $consultas)
    {
    }

    public function registrar(Request $request, Consulta $consulta, RegistrarSesionDeConsulta $accion): JsonResponse
    {
        $datos = $request->validate([
            'dispositivo_id' => ['required', 'string', 'min:8', 'max:64', 'regex:/^[A-Za-z0-9\-]+$/'],
            'tomar' => ['sometimes', 'boolean'],
        ]);

        $contexto = $request->attributes->get('contexto_clinico');
        $registros = $this->registrosDelMedico($contexto['medico']);

        $consulta = $this->consultas->consulta($registros, (int) $consulta->id, $contexto['reg_medico']);

        $resultado = $accion->ejecutar(
            $consulta,
            $request->user()?->id,
            $datos['dispositivo_id'],
            (bool) ($datos['tomar'] ?? false),
            now(),
        );

        return response()->json($resultado);
    }
}
