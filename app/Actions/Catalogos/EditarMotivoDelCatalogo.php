<?php

namespace App\Actions\Catalogos;

use App\Actions\Consulta\AgregarMotivoAlCatalogo;
use App\Models\MotivoConsulta;

/**
 * Editar la descripción de un motivo de consulta del catálogo (WEB-8.1b). La regla común (sólo lo que cambió, sin
 * duplicar, con rastro por columna) es la de `EditarDescripcionDelCatalogo`; el tope de 40 caracteres es el de
 * `AgregarMotivoAlCatalogo` (`d_a_motivos_consulta.srd`: `edit.limit=40`, `edit.case=upper`).
 *
 * Los motivos que una consulta ya tiene guardan además su propia copia de la descripción
 * (`motivo_consulta_paciente.descripcion`); la web **no la reescribe**: la consulta resuelve primero por el catálogo
 * (`MotivosDeLaConsulta::deLaConsulta`), así que el motivo renombrado se ve renombrado, y la copia sólo es el respaldo
 * si el código desaparece.
 */
final class EditarMotivoDelCatalogo extends EditarDescripcionDelCatalogo
{
    protected function modelo(): string
    {
        return MotivoConsulta::class;
    }

    protected function tabla(): string
    {
        return 'motivos_consulta';
    }

    protected function columnaDelCodigo(): string
    {
        return 'codemotivo';
    }

    protected function columna(): string
    {
        return 'descripcion';
    }

    protected function largo(): int
    {
        return AgregarMotivoAlCatalogo::LARGO_MAXIMO;
    }

    protected function singular(): string
    {
        return 'motivo';
    }
}
