<?php

namespace App\Clinica\Impresion;

use App\Models\RecipeFormato;

/**
 * **Cómo sale la hoja del récipe** (WEB-5.2): el modo (una hoja con "Rp." + "Indicaciones", o sólo la mitad de
 * las indicaciones) y con qué nombre sale cada medicamento (comercial o genérico).
 *
 * Son las dos opciones que la pantalla de récipes del legado ofrece (las casillas "Genérico / Comercial" y
 * "Rp. / Indicaciones") y que el móvil recuerda en el teléfono. En la web **son del médico** y se guardan en
 * su fila de `recipe_formatos`, para que el médico y su asistente impriman igual; si el médico nunca eligió,
 * valen los defaults del legado: **Rp. + Indicaciones** y **nombre comercial**.
 */
final class OpcionesDeRecipe
{
    public function __construct(
        public readonly string $modo = RecipeFormato::MODO_RP_E_INDICACIONES,
        public readonly string $nombre = RecipeFormato::NOMBRE_COMERCIAL,
    ) {
    }

    public static function porDefecto(): self
    {
        return new self();
    }

    /** Lo guardado en la fila del médico; un valor ausente o desconocido vuelve al default. */
    public static function desde(?RecipeFormato $formato): self
    {
        return self::de($formato?->modo_impresion, $formato?->nombre_medicamento);
    }

    public static function de(?string $modo, ?string $nombre): self
    {
        return new self(
            in_array($modo, RecipeFormato::MODOS_IMPRESION, true) ? $modo : RecipeFormato::MODO_RP_E_INDICACIONES,
            in_array($nombre, RecipeFormato::NOMBRES_MEDICAMENTO, true) ? $nombre : RecipeFormato::NOMBRE_COMERCIAL,
        );
    }

    public function conRpEIndicaciones(): bool
    {
        return $this->modo === RecipeFormato::MODO_RP_E_INDICACIONES;
    }

    public function esGenerico(): bool
    {
        return $this->nombre === RecipeFormato::NOMBRE_GENERICO;
    }
}
