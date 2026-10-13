<?php

namespace App\Clinica\Impresion;

use App\Clinica\Verificacion\CredencialesDeVerificacion;
use App\Clinica\Verificacion\GeneradorDeQr;
use App\Clinica\Verificacion\UrlDeVerificacion;
use App\Models\RecipeVerificacion;

/**
 * **El QR de verificación de un récipe tal como se imprime** (WEB-5.6): el dibujo (SVG), la dirección que se escribe al
 * lado (`doctorisimo.app/verificar`) y el código corto (`ABCD-EFGH`) para teclearlo si el QR no se lee.
 *
 * Es un objeto de valores ya resuelto: la hoja sólo lo pinta. Del récipe real sale de su `RecipeVerificacion`; el de la
 * hoja de prueba (WEB-8.2) es un **ejemplo** rotulado como tal, cuyo QR apunta a una dirección que la página pública reconoce
 * como ejemplo y que no verifica ningún récipe.
 */
final class QrDelRecipe
{
    public function __construct(
        public readonly string $svg,
        public readonly string $direccion,
        public readonly string $codigo,
        public readonly bool $ejemplo = false,
    ) {
    }

    public static function deLaVerificacion(RecipeVerificacion $verificacion, GeneradorDeQr $generador): self
    {
        return new self(
            $generador->svg(UrlDeVerificacion::deToken($verificacion->token)),
            UrlDeVerificacion::visible(),
            CredencialesDeVerificacion::formatearCodigo($verificacion->codigo),
        );
    }

    /** El QR de la hoja de prueba: lleva a `…/verificar/ejemplo`, que no es ningún récipe. */
    public static function deEjemplo(GeneradorDeQr $generador = new GeneradorDeQr()): self
    {
        return new self(
            $generador->svg(UrlDeVerificacion::deToken(UrlDeVerificacion::TOKEN_DE_EJEMPLO)),
            UrlDeVerificacion::visible(),
            'EJEMPLO',
            true,
        );
    }
}
