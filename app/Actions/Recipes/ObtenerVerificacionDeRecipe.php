<?php

namespace App\Actions\Recipes;

use App\Clinica\Verificacion\CredencialesDeVerificacion;
use App\Models\RecipeVerificacion;
use Illuminate\Database\QueryException;

/**
 * **Obtiene —o crea— la verificación pública de un récipe** (WEB-5.6): el token del QR y el código corto.
 *
 * Se llama **al imprimir desde la web** (el médico y la secretaría imprimen la misma hoja) y es **idempotente**:
 * el mismo récipe —`reg_medico` + `nrohistoria` + `nroconsulta` + `tipo` + `recipe`— devuelve siempre la misma fila, así que
 * reimprimir da el mismo QR aunque el médico haya corregido el récipe entre una impresión y otra (el token identifica al
 * récipe, no a su contenido).
 *
 * ## Dos impresiones a la vez
 *
 * La unicidad la garantiza el **índice único** de la tabla, no un chequeo previo: se busca, y si no hay se inserta. Si dos
 * peticiones llegan juntas y ambas ven "no hay", la segunda inserción choca con el índice; en ese caso se **vuelve a
 * buscar** y se devuelve la fila de la primera (nadie ve un error). Un choque que no sea el del récipe —el token o el código,
 * con probabilidad despreciable— se reintenta con credenciales nuevas.
 *
 * Es de AppDDR (tabla nueva): no pasa por `sync_changes` ni se sincroniza. **El token no se loguea.**
 */
final class ObtenerVerificacionDeRecipe
{
    private const INTENTOS = 5;

    /**
     * @param  string  $tipo  `RecipeVerificacion::TIPO_PACIENTE` o `TIPO_PAREJA`
     * @param  int  $recipe  el número del récipe del paciente; el de la pareja es siempre 1
     */
    public function ejecutar(string $regMedico, int $nrohistoria, int $nroconsulta, string $tipo, int $recipe, ?int $medicoId = null): RecipeVerificacion
    {
        if ($tipo === RecipeVerificacion::TIPO_PAREJA) {
            $recipe = 1;
        }

        $buscar = fn (): ?RecipeVerificacion => RecipeVerificacion::query()
            ->where('reg_medico', $regMedico)
            ->where('nrohistoria', $nrohistoria)
            ->where('nroconsulta', $nroconsulta)
            ->where('tipo', $tipo)
            ->where('recipe', $recipe)
            ->first();

        $existente = $buscar();
        if ($existente !== null) {
            return $existente;
        }

        for ($intento = 1; ; $intento++) {
            try {
                return RecipeVerificacion::create([
                    'token' => CredencialesDeVerificacion::nuevoToken(),
                    'codigo' => CredencialesDeVerificacion::nuevoCodigo(),
                    'reg_medico' => $regMedico,
                    'medico_id' => $medicoId,
                    'nrohistoria' => $nrohistoria,
                    'nroconsulta' => $nroconsulta,
                    'tipo' => $tipo,
                    'recipe' => $recipe,
                ]);
            } catch (QueryException $excepcion) {
                // Otra impresión simultánea ganó la carrera: se usa su fila.
                $ganadora = $buscar();
                if ($ganadora !== null) {
                    return $ganadora;
                }

                // Chocó el token o el código (casi imposible) o falló otra cosa: se reintenta unas veces y se corta.
                if ($intento >= self::INTENTOS) {
                    throw $excepcion;
                }
            }
        }
    }
}
