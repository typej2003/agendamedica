<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Services\CatalogoMotivosConsulta;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Alta de un motivo en el catálogo de motivos de consulta del médico (ROADMAP.md Paso 18.B2).
 *
 * **Online-only a propósito, igual que `ConfiguracionMedicoController`**: no pasa por la cola de
 * `sync-app-data`. Crear un motivo nuevo es poco frecuente (al inicio se cargan varios y después
 * casi siempre se reusan), así que no vale la pena el id local, el `remote_id` y el reintento que
 * necesitan las tablas con altas offline: el app espera la respuesta y guarda el motivo con su id
 * real. Si no hay conexión, el app lo avisa y el médico elige uno de los existentes.
 *
 * Agregar un motivo **a una consulta** sí es offline y va por `sync-app-data`
 * (`motivo_consulta_paciente`).
 */
class MotivoConsultaController extends Controller
{
    public function crear(Request $request, CatalogoMotivosConsulta $catalogo)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario no autenticado.'], 401);
        }

        $medico = Medico::where('user_id', $user->id)->orWhere('email', $user->email)->first();
        if (!$medico) {
            return response()->json(['message' => 'Esta cuenta no tiene un médico asociado.'], 403);
        }

        // El límite real (40, ya normalizado) lo valida el servicio; este es solo un tope para no
        // procesar texto absurdo.
        $datos = $request->validate(['descripcion' => 'required|string|max:200']);

        $registros = MedicoRegistro::where('medico_id', $medico->id)->pluck('reg_medico')->filter()->toArray();
        if (!empty($medico->reg_medico)) {
            $registros[] = $medico->reg_medico;
        }
        $registros = array_values(array_unique($registros));
        if (!$medico->regMedicoPrincipal()) {
            return response()->json(['message' => 'Esta cuenta no tiene un número de registro médico configurado.'], 422);
        }

        try {
            [$motivo, $creado] = $catalogo->obtenerOCrear($medico, $registros, $datos['descripcion']);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['descripcion' => [$e->getMessage()]],
            ], 422);
        }

        return response()->json([
            'id' => $motivo->id,
            'codemotivo' => $motivo->codemotivo,
            'descripcion' => $motivo->descripcion,
            'reg_medico' => $motivo->reg_medico,
            'creado' => $creado,
        ], $creado ? 201 : 200);
    }
}
