<?php

namespace App\Clinica\Vacunas;

/**
 * **Las etiquetas de los codigos de vacunas** (WEB-12.8), en un solo lugar para cambiarlas facil. Convencion **provisional** (decision del
 * usuario, 2026-10-17; Alexander no respondio): `vacunas.fija` = `O` Obligatoria / `R` Recomendada. El **refuerzo** de una dosis no se
 * reconoce por `vacunas_dosis.obligatoria` (el respaldo la trae `R` en todas) sino por el rotulo de la dosis (`dosis_descri`).
 */
final class ConvencionDeVacunas
{
    public const FIJA = ['O' => 'Obligatoria', 'R' => 'Recomendada'];

    public const SEXO = ['A' => 'Ambos', 'M' => 'Niños', 'F' => 'Niñas'];

    public static function fija(?string $codigo): string
    {
        return self::FIJA[strtoupper(trim((string) $codigo))] ?? '—';
    }

    public static function sexo(?string $codigo): string
    {
        return self::SEXO[strtoupper(trim((string) $codigo))] ?? 'Ambos';
    }

    /** ¿El rotulo de la dosis dice que es un refuerzo? */
    public static function esRefuerzo(?string $rotulo): bool
    {
        return str_contains(mb_strtolower((string) $rotulo), 'refuerzo');
    }
}
