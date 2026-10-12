<?php

namespace App\Actions\Catalogos;

use App\Actions\Consulta\AgregarDiagnosticoAlCatalogo;
use App\Models\Diagnostico;

/**
 * Editar la descripción de un diagnóstico del catálogo (WEB-8.1b). La regla común (sólo lo que cambió, sin duplicar, con
 * rastro por columna) es la de `EditarDescripcionDelCatalogo`; el tope de 50 caracteres es el de
 * `AgregarDiagnosticoAlCatalogo` (`d_a_diagnostico.srd`: `edit.limit=50`, `edit.case=upper`).
 */
final class EditarDiagnosticoDelCatalogo extends EditarDescripcionDelCatalogo
{
    protected function modelo(): string
    {
        return Diagnostico::class;
    }

    protected function tabla(): string
    {
        return 'diagnosticos';
    }

    protected function columnaDelCodigo(): string
    {
        return 'codediagnostico';
    }

    protected function columna(): string
    {
        return 'descripcion';
    }

    protected function largo(): int
    {
        return AgregarDiagnosticoAlCatalogo::LARGO_MAXIMO;
    }

    protected function singular(): string
    {
        return 'diagnóstico';
    }
}
