<?php

namespace App\Clinica\Impresion;

use App\Actions\Recipes\ObtenerVerificacionDeRecipe;
use App\Clinica\Verificacion\GeneradorDeQr;
use App\Models\Consulta;
use App\Models\Medico;
use App\Models\RecipeVerificacion;

/**
 * **Le pone su QR de verificación a los récipes que se van a imprimir** (WEB-5.6).
 *
 * Lo usan las dos entradas a la hoja del récipe —el médico desde la consulta y la secretaría desde la cita atendida—
 * justo antes de armar las hojas, para que las dos impriman **el mismo QR** (el token es del récipe, no de quien
 * imprime). Obtiene o crea la verificación de cada récipe (`ObtenerVerificacionDeRecipe`, idempotente) y dibuja su QR
 * (`GeneradorDeQr`). Los documentos que no son récipes pasan sin cambios: por ahora sólo el récipe lleva QR.
 */
final class QrParaLosRecipes
{
    public function __construct(
        private ObtenerVerificacionDeRecipe $verificaciones,
        private GeneradorDeQr $generador,
    ) {
    }

    /**
     * @param  array<int,mixed>  $documentos  lo que arma `RecipesParaImprimir` / `DocumentosParaImprimir`
     * @return array<int,mixed>  los mismos, con el QR en cada `DocumentoDeRecipe`
     */
    public function agregar(array $documentos, Consulta $consulta, ?Medico $medico): array
    {
        return array_map(function ($documento) use ($consulta, $medico) {
            if (! $documento instanceof DocumentoDeRecipe) {
                return $documento;
            }

            $verificacion = $this->verificaciones->ejecutar(
                (string) $consulta->reg_medico,
                (int) $consulta->numhistoria,
                (int) $consulta->nroconsulta,
                $documento->esDeLaPareja() ? RecipeVerificacion::TIPO_PAREJA : RecipeVerificacion::TIPO_PACIENTE,
                $documento->esDeLaPareja() ? 1 : (int) $documento->numero,
                $medico?->id,
            );

            return $documento->conQr(QrDelRecipe::deLaVerificacion($verificacion, $this->generador));
        }, $documentos);
    }
}
