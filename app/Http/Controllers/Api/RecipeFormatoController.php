<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RecipeFormatoResource;
use App\Models\Medico;
use App\Models\RecipeFormato;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Formato de impresión del récipe y firma/sello del médico (ROADMAP.md Paso 18.A).
 *
 * Online-only, por el mismo motivo que `ConfiguracionMedicoController`: es una fila única por médico,
 * de baja frecuencia, y no encaja en la cola de `sync-app-data`. El teléfono la recibe completa en cada
 * sync (`formato_recipe`) y guarda copia local para imprimir sin conexión.
 *
 * Actualización **parcial**: lo que no viene en la petición queda como estaba, también dentro de
 * `elementos` (mandar solo `elementos[rif][negrita]` no resetea el resto del RIF ni los demás bloques).
 */
class RecipeFormatoController extends Controller
{
    public function actualizar(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario no autenticado.'], 401);
        }

        $medico = Medico::where('user_id', $user->id)->orWhere('email', $user->email)->first();
        if (!$medico) {
            return response()->json(['message' => 'Esta cuenta no tiene un médico asociado.'], 403);
        }

        $regMedico = $medico->regMedicoPrincipal();
        if (!$regMedico) {
            return response()->json([
                'message' => 'Esta cuenta no tiene un número de registro médico principal configurado.',
            ], 422);
        }

        $datos = $request->validate($this->reglas());

        $formato = RecipeFormato::firstOrNew(['reg_medico' => $regMedico]);

        if (isset($datos['elementos'])) {
            $formato->elementos = array_replace_recursive(
                $formato->elementos ?? [],
                $this->soloOpcionesConocidas($datos['elementos']),
            );
        }
        foreach (['color_linea', 'sello_posicion'] as $campo) {
            if (array_key_exists($campo, $datos)) {
                $formato->$campo = $datos[$campo];
            }
        }

        foreach (['firma', 'sello'] as $imagen) {
            if ($request->hasFile($imagen)) {
                $this->borrarArchivo($formato->$imagen);
                $formato->$imagen = $request->file($imagen)->store("recipe/{$imagen}s", 'public');
            } elseif ($request->boolean("{$imagen}_eliminar")) {
                $this->borrarArchivo($formato->$imagen);
                $formato->$imagen = null;
            }
        }

        $formato->save();

        return new RecipeFormatoResource($formato);
    }

    private function reglas(): array
    {
        $reglas = [
            'color_linea' => ['sometimes', Rule::in(RecipeFormato::COLORES_LINEA)],
            'sello_posicion' => ['sometimes', Rule::in(RecipeFormato::POSICIONES_SELLO)],
            'firma' => 'sometimes|image|max:2048',
            'sello' => 'sometimes|image|max:2048',
            'firma_eliminar' => 'sometimes|boolean',
            'sello_eliminar' => 'sometimes|boolean',
            'elementos' => 'sometimes|array',
        ];

        foreach (RecipeFormato::ELEMENTOS as $elemento) {
            $base = "elementos.{$elemento}";
            $reglas["{$base}.alineacion"] = ['sometimes', Rule::in(RecipeFormato::ALINEACIONES)];
            $reglas["{$base}.borde"] = 'sometimes|boolean';
            $reglas["{$base}.visible"] = 'sometimes|boolean';

            if (in_array($elemento, RecipeFormato::ELEMENTOS_TEXTO, true)) {
                $reglas["{$base}.fuente"] = ['sometimes', Rule::in(RecipeFormato::FUENTES)];
                $reglas["{$base}.negrita"] = 'sometimes|boolean';
                $reglas["{$base}.italica"] = 'sometimes|boolean';
                $reglas["{$base}.subrayado"] = 'sometimes|boolean';
            } else {
                $reglas["{$base}.tamano"] = ['sometimes', Rule::in(RecipeFormato::TAMANOS_LOGO)];
            }
        }

        return $reglas;
    }

    /**
     * Deja en `elementos` solo los elementos y opciones que tienen regla. Hace falta a mano: la regla
     * `elementos => array` hace que `validate()` devuelva el arreglo entero, y sin este filtro un
     * elemento u opción inventados terminarían guardados en el JSON.
     *
     * De paso normaliza los booleanos: en multipart llegan como "1"/"0", y guardados como texto el
     * cliente tendría que interpretar `"0"` como falso.
     */
    private function soloOpcionesConocidas(array $elementos): array
    {
        $opcionesBooleanas = ['negrita', 'italica', 'subrayado', 'borde', 'visible'];
        $resultado = [];

        foreach (RecipeFormato::ELEMENTOS as $elemento) {
            if (!is_array($elementos[$elemento] ?? null)) {
                continue;
            }
            $permitidas = in_array($elemento, RecipeFormato::ELEMENTOS_TEXTO, true)
                ? ['alineacion', 'fuente', 'negrita', 'italica', 'subrayado', 'borde', 'visible']
                : ['alineacion', 'tamano', 'borde', 'visible'];

            foreach (array_intersect_key($elementos[$elemento], array_flip($permitidas)) as $opcion => $valor) {
                $resultado[$elemento][$opcion] = in_array($opcion, $opcionesBooleanas, true)
                    ? filter_var($valor, FILTER_VALIDATE_BOOLEAN)
                    : $valor;
            }
        }

        return $resultado;
    }

    /** No hay historial de firmas: cada reemplazo borra el archivo anterior para no dejar huérfanos. */
    private function borrarArchivo(?string $ruta): void
    {
        if ($ruta) {
            Storage::disk('public')->delete($ruta);
        }
    }
}
