<?php

namespace App\Actions\Catalogos;

use App\Clinica\Catalogos\UsoDeUnItemDelCatalogo;
use App\Models\Diagnostico;
use Illuminate\Database\Eloquent\Model;

/**
 * Quitar un diagnóstico del catálogo (WEB-8.1b): en uso = los diagnósticos de las consultas (`diagnostico_paciente`).
 * La regla común (borrar si no tiene uso; si lo tiene, avisar dónde, todo en una transacción con candado y con su
 * `deleted` en `sync_changes`) es la de `QuitarDelCatalogo`.
 */
final class QuitarDiagnosticoDelCatalogo extends QuitarDelCatalogo
{
    protected function modelo(): string
    {
        return Diagnostico::class;
    }

    protected function tabla(): string
    {
        return 'diagnosticos';
    }

    protected function uso(Model $fila, array $registros): UsoDeUnItemDelCatalogo
    {
        /** @var Diagnostico $fila */
        return UsoDeUnItemDelCatalogo::diagnostico((string) $fila->reg_medico, (string) $fila->codediagnostico);
    }

    protected function nombre(Model $fila): string
    {
        /** @var Diagnostico $fila */
        $nombre = trim((string) $fila->descripcion);

        return $nombre !== '' ? $nombre : 'diagnóstico ' . trim((string) $fila->codediagnostico);
    }
}
