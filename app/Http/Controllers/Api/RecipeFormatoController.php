<?php

namespace App\Http\Controllers\Api;

use App\Actions\Configuracion\GuardarFormatoDelRecipe;
use App\Actions\Configuracion\ReglasDelFormato;
use App\Http\Controllers\Controller;
use App\Http\Resources\RecipeFormatoResource;
use App\Models\Medico;
use Illuminate\Http\Request;

/**
 * Formato de impresión del récipe y firma/sello del médico (ROADMAP.md Paso 18.A).
 *
 * Online-only, por el mismo motivo que `ConfiguracionMedicoController`: es una fila única por médico,
 * de baja frecuencia, y no encaja en la cola de `sync-app-data`. El teléfono la recibe completa en cada
 * sync (`formato_recipe`) y guarda copia local para imprimir sin conexión.
 *
 * Actualización **parcial**: lo que no viene en la petición queda como estaba, también dentro de
 * `elementos` (mandar solo `elementos[rif][negrita]` no resetea el resto del RIF ni los demás bloques).
 *
 * Desde WEB-8.2 las reglas de validación (`ReglasDelFormato`) y la escritura (`GuardarFormatoDelRecipe`) son las mismas
 * que usa la pantalla "Formato de impresión" de la web clínica; este controlador sólo traduce HTTP y no cambió su JSON.
 */
class RecipeFormatoController extends Controller
{
    public function actualizar(Request $request, GuardarFormatoDelRecipe $guardar)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario no autenticado.'], 401);
        }

        $medico = Medico::where('user_id', $user->id)->orWhere('email', $user->email)->first();
        if (!$medico) {
            return response()->json(['message' => 'Esta cuenta no tiene un médico asociado.'], 403);
        }

        if (!$medico->regMedicoPrincipal()) {
            return response()->json([
                'message' => 'Esta cuenta no tiene un número de registro médico principal configurado.',
            ], 422);
        }

        $datos = $request->validate(ReglasDelFormato::reglas());

        $formato = $guardar->ejecutar(
            $medico,
            $datos,
            $request->hasFile('firma') ? $request->file('firma') : null,
            $request->hasFile('sello') ? $request->file('sello') : null,
            $request->boolean('firma_eliminar'),
            $request->boolean('sello_eliminar'),
        );

        return new RecipeFormatoResource($formato);
    }
}
