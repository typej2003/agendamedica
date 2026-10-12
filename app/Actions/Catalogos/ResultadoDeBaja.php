<?php

namespace App\Actions\Catalogos;

/**
 * Qué pasó al quitar algo de un catálogo (WEB-8.1a): se quitó, o no se quitó porque está en uso y el aviso dice
 * **dónde** (para que el médico decida qué hacer; no se borra nada en silencio ni se rechaza a secas).
 */
final class ResultadoDeBaja
{
    private function __construct(
        public readonly bool $quitado,
        public readonly string $nombre,
        public readonly ?string $enUso,
        public readonly int $medicamentos,
    ) {
    }

    /** @param  int  $medicamentos  cuántos medicamentos de un tratamiento se quitaron con él (0 en un medicamento) */
    public static function quitado(string $nombre, int $medicamentos = 0): self
    {
        return new self(true, $nombre, null, $medicamentos);
    }

    /** @param  string  $dondeSeUsa  la frase de `UsoDeUnMedicamento::descripcion()` */
    public static function enUso(string $nombre, string $dondeSeUsa): self
    {
        return new self(false, $nombre, $dondeSeUsa, 0);
    }
}
