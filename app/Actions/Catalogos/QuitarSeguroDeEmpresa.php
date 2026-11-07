<?php

namespace App\Actions\Catalogos;

use App\Clinica\Catalogos\UsoDeUnItemDelCatalogo;
use App\Models\SegEmp;
use Illuminate\Database\Eloquent\Model;

/**
 * **Quitar un seguro / empresa del catálogo del consultorio** (O-62): se borra si **ningún paciente** de ese
 * consultorio lo tiene como su seguro; si lo tiene, no se borra y el aviso dice cuántos y quiénes.
 *
 * El escritorio borraba sin comprobar nada (`ue_eliminar` de `w_agregar_seguro_empresa.srw` hace un `delete from
 * seg_emp where codesegemp = …`): el paciente quedaba con un código que ya no estaba en el catálogo y la ficha
 * mostraba el número pelado. Es la misma regla de los otros catálogos del médico (WEB-8.1).
 */
final class QuitarSeguroDeEmpresa extends QuitarDelCatalogo
{
    protected function modelo(): string
    {
        return SegEmp::class;
    }

    protected function tabla(): string
    {
        return 'seg_emp';
    }

    protected function uso(Model $fila, array $registros): UsoDeUnItemDelCatalogo
    {
        /** @var SegEmp $fila */
        return UsoDeUnItemDelCatalogo::seguro((string) $fila->reg_medico, (string) $fila->codesegemp);
    }

    protected function nombre(Model $fila): string
    {
        /** @var SegEmp $fila */
        $nombre = trim((string) $fila->nombre);

        return $nombre !== '' ? $nombre : 'seguro ' . trim((string) $fila->codesegemp);
    }
}
