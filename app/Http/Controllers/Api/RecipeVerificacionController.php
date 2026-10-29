<?php

namespace App\Http\Controllers\Api;

use App\Actions\Recipes\ObtenerVerificacionDeRecipe;
use App\Clinica\Verificacion\UrlDeVerificacion;
use App\Http\Controllers\Controller;
use App\Models\Consulta;
use App\Models\Medico;
use App\Models\MedicoRegistro;
use App\Models\RecipeVerificacion;
use Illuminate\Http\Request;

/**
 * **La verificación pública del récipe desde el móvil** (WEB-5.6): devuelve la URL del QR y el código corto
 * para la hoja que arma el teléfono.
 *
 * Es online-only por la misma razón que la notificación de una cita: el token lo genera el servidor y la
 * impresión sin conexión no puede inventarlo. El app pide la verificación **al imprimir**; si no hay red,
 * imprime sin QR y avisa, en vez de guardar una promesa que después no se puede cumplir.
 *
 * La Action es la misma de la web (`ObtenerVerificacionDeRecipe`), así que reimprimir devuelve el mismo QR.
 */
class RecipeVerificacionController extends Controller
{
    public function obtener(Request $request, ObtenerVerificacionDeRecipe $verificacion)
    {
        $datos = $request->validate([
            'numhistoria' => ['required', 'integer'],
            'nroconsulta' => ['required', 'integer'],
            'numero' => ['required', 'integer', 'min:1'],
        ]);

        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Usuario no autenticado.'], 401);
        }

        $medico = Medico::where('user_id', $user->id)->orWhere('email', $user->email)->first();
        if (! $medico) {
            return response()->json(['message' => 'Esta cuenta no tiene un médico asociado.'], 403);
        }

        $registros = MedicoRegistro::where('medico_id', $medico->id)
            ->pluck('reg_medico')
            ->filter()
            ->push($medico->reg_medico)
            ->filter()
            ->unique()
            ->values()
            ->all();

        $consulta = Consulta::whereIn('reg_medico', $registros)
            ->where('numhistoria', $datos['numhistoria'])
            ->where('nroconsulta', $datos['nroconsulta'])
            ->first();

        if (! $consulta) {
            return response()->json(['message' => 'La consulta no existe o no pertenece a este médico.'], 404);
        }

        $fila = $verificacion->ejecutar(
            (string) $consulta->reg_medico,
            (int) $consulta->numhistoria,
            (int) $consulta->nroconsulta,
            RecipeVerificacion::TIPO_PACIENTE,
            (int) $datos['numero'],
            $medico->id,
        );

        return response()->json([
            'url' => UrlDeVerificacion::deToken($fila->token),
            'codigo' => $fila->codigo,
        ]);
    }
}
