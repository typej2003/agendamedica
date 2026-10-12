<?php

namespace App\Actions\Catalogos;

use App\Clinica\Catalogos\UsoDeUnItemDelCatalogo;
use App\Models\TipoExamen;
use Illuminate\Database\Eloquent\Model;

/**
 * Quitar un tipo de examen (WEB-8.1b): en uso = los exámenes del catálogo que cuelgan de él (`examenes.codetipo`).
 *
 * El escritorio **no** dejaba borrar tipos (el botón *Eliminar* de `w_agregar_tipo_exameen` tiene todo el `clicked`
 * comentado, `:357-366`): la web sí, pero sólo cuando ningún examen cuelga de él. La regla común es la de `QuitarDelCatalogo`.
 */
final class QuitarTipoDeExamenDelCatalogo extends QuitarDelCatalogo
{
    protected function modelo(): string
    {
        return TipoExamen::class;
    }

    protected function tabla(): string
    {
        return 'tipos_examenes';
    }

    protected function uso(Model $fila, array $registros): UsoDeUnItemDelCatalogo
    {
        /** @var TipoExamen $fila */
        return UsoDeUnItemDelCatalogo::tipoDeExamen((string) $fila->reg_medico, (string) $fila->codetipo);
    }

    protected function nombre(Model $fila): string
    {
        /** @var TipoExamen $fila */
        $nombre = trim((string) $fila->tipo);

        return $nombre !== '' ? $nombre : 'tipo ' . trim((string) $fila->codetipo);
    }
}
