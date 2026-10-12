<?php

namespace App\Clinica\Verificacion;

use App\Models\Consulta;
use App\Models\RecipeVerificacion;

/**
 * **Las verificaciones (QR) de los récipes de una consulta** (WEB-5.6), para la sección de récipes del médico: en qué estado
 * está el QR de cada uno (`activa` o `anulada`; el que nunca se imprimió no tiene fila) y la búsqueda de una por récipe, que
 * es lo que necesitan «Anular» y «Reactivar».
 *
 * Todo se acota por el `reg_medico`, la historia y el número de la consulta: la verificación de otra consulta no se ve. Es de
 * sólo lectura; la escritura es `App\Actions\Recipes\{ObtenerVerificacionDeRecipe,AnularVerificacionDeRecipe,ReactivarVerificacionDeRecipe}`.
 */
final class VerificacionesDeLaConsulta
{
    public const ACTIVA = 'activa';

    public const ANULADA = 'anulada';

    /**
     * El estado del QR de cada récipe de la consulta.
     *
     * @return array{paciente:array<int,string>, pareja:?string}
     */
    public function estados(Consulta $consulta): array
    {
        $paciente = [];
        $pareja = null;

        foreach ($this->filas($consulta)->get() as $verificacion) {
            $estado = $verificacion->estaAnulada() ? self::ANULADA : self::ACTIVA;

            if ($verificacion->esDeLaPareja()) {
                $pareja = $estado;
            } else {
                $paciente[(int) $verificacion->recipe] = $estado;
            }
        }

        return ['paciente' => $paciente, 'pareja' => $pareja];
    }

    /** La verificación de un récipe del paciente (`$numero`) o de la pareja (`null`), o `null` si nunca se imprimió. */
    public function de(Consulta $consulta, ?int $numero): ?RecipeVerificacion
    {
        $filas = $this->filas($consulta);

        return $numero === null
            ? $filas->where('tipo', RecipeVerificacion::TIPO_PAREJA)->first()
            : $filas->where('tipo', RecipeVerificacion::TIPO_PACIENTE)->where('recipe', $numero)->first();
    }

    private function filas(Consulta $consulta)
    {
        return RecipeVerificacion::query()
            ->where('reg_medico', (string) $consulta->reg_medico)
            ->where('nrohistoria', (int) $consulta->numhistoria)
            ->where('nroconsulta', (int) $consulta->nroconsulta);
    }
}
