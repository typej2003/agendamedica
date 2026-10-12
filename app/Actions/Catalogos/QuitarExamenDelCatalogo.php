<?php

namespace App\Actions\Catalogos;

use App\Clinica\Catalogos\UsoDeUnItemDelCatalogo;
use App\Models\Examen;
use Illuminate\Database\Eloquent\Model;

/**
 * Quitar un examen del catálogo (WEB-8.1b): en uso = los exámenes de las consultas (`examen_paciente`) y los de la
 * pareja (`examen_pareja`). La regla común es la de `QuitarDelCatalogo`.
 */
final class QuitarExamenDelCatalogo extends QuitarDelCatalogo
{
    protected function modelo(): string
    {
        return Examen::class;
    }

    protected function tabla(): string
    {
        return 'examenes';
    }

    protected function uso(Model $fila, array $registros): UsoDeUnItemDelCatalogo
    {
        /** @var Examen $fila */
        return UsoDeUnItemDelCatalogo::examen((string) $fila->reg_medico, (string) $fila->codeexamen);
    }

    protected function nombre(Model $fila): string
    {
        /** @var Examen $fila */
        $nombre = trim((string) $fila->examen);

        return $nombre !== '' ? $nombre : 'examen ' . trim((string) $fila->codeexamen);
    }
}
