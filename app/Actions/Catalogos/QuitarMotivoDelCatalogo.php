<?php

namespace App\Actions\Catalogos;

use App\Clinica\Catalogos\UsoDeUnItemDelCatalogo;
use App\Models\MotivoConsulta;
use Illuminate\Database\Eloquent\Model;

/**
 * Quitar un motivo de consulta del catálogo (WEB-8.1b): en uso = los motivos de las consultas
 * (`motivo_consulta_paciente`). No son los motivos de la **cita** (`motivo_cita`, WEB-2.17). La regla común es la de
 * `QuitarDelCatalogo`.
 */
final class QuitarMotivoDelCatalogo extends QuitarDelCatalogo
{
    protected function modelo(): string
    {
        return MotivoConsulta::class;
    }

    protected function tabla(): string
    {
        return 'motivos_consulta';
    }

    protected function uso(Model $fila, array $registros): UsoDeUnItemDelCatalogo
    {
        /** @var MotivoConsulta $fila */
        return UsoDeUnItemDelCatalogo::motivo((string) $fila->reg_medico, (string) $fila->codemotivo);
    }

    protected function nombre(Model $fila): string
    {
        /** @var MotivoConsulta $fila */
        $nombre = trim((string) $fila->descripcion);

        return $nombre !== '' ? $nombre : 'motivo ' . trim((string) $fila->codemotivo);
    }
}
