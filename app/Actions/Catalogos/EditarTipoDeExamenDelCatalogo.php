<?php

namespace App\Actions\Catalogos;

use App\Models\TipoExamen;

/**
 * Editar el nombre de un tipo de examen (WEB-8.1b). La regla común (sólo lo que cambió, sin duplicar, con rastro por
 * columna) es la de `EditarDescripcionDelCatalogo`; el tope de 40 caracteres y las mayúsculas son los de
 * `d_a_tipo_examen.srd` (`tipo`: `edit.limit=40`, `edit.case=upper`).
 */
final class EditarTipoDeExamenDelCatalogo extends EditarDescripcionDelCatalogo
{
    public const LARGO = 40;

    protected function modelo(): string
    {
        return TipoExamen::class;
    }

    protected function tabla(): string
    {
        return 'tipos_examenes';
    }

    protected function columnaDelCodigo(): string
    {
        return 'codetipo';
    }

    protected function columna(): string
    {
        return 'tipo';
    }

    protected function largo(): int
    {
        return self::LARGO;
    }

    protected function singular(): string
    {
        return 'tipo de examen';
    }
}
