<?php

namespace App\Actions\Catalogos;

use App\Clinica\Catalogos\UsoDeUnItemDelCatalogo;
use App\Models\MotivoCita;
use Illuminate\Database\Eloquent\Model;

/**
 * Quitar un motivo de cita del catálogo (WEB-8.1c): se borra **si no tiene uso**. En uso = alguna cita (`cola`) del médico
 * lo nombra por su código, de cualquier fecha (pasada o futura), en `tipo` (lo que escriben el escritorio y la web) o en
 * `motivo` (lo que escribe el móvil); entonces no se borra y el resultado dice cuántas citas son y desde cuándo.
 *
 * Una salvedad medida: si **otra fila** del mismo médico conserva ese mismo código (el repetido por sede que trae la
 * sincronización), las citas siguen resolviendo su nombre por ese código y quitar esta fila no deja nada huérfano; así se
 * limpian los repetidos. La comprobación y el borrado van en una transacción con la fila bajo candado, y la regla común
 * es la de `QuitarDelCatalogo` (8.1b). El escritorio tiene `ue_eliminar` comentado (`w_manteniento.srw:21-32`): nunca
 * permitió borrar un motivo de cita.
 */
final class QuitarMotivoDeCita extends QuitarDelCatalogo
{
    protected function modelo(): string
    {
        return MotivoCita::class;
    }

    protected function tabla(): string
    {
        return 'motivo_cita';
    }

    protected function uso(Model $fila, array $registros): UsoDeUnItemDelCatalogo
    {
        /** @var MotivoCita $fila */
        $sigue = MotivoCita::where('reg_medico', $fila->reg_medico)
            ->where('codigo', $fila->codigo)
            ->where('id', '<>', $fila->id)
            ->exists();

        return UsoDeUnItemDelCatalogo::motivoDeCita((string) $fila->reg_medico, (string) $fila->codigo, $sigue);
    }

    protected function nombre(Model $fila): string
    {
        /** @var MotivoCita $fila */
        $razon = trim((string) $fila->tipo_atencion);

        return $razon !== '' ? $razon : 'motivo ' . trim((string) $fila->codigo);
    }
}
