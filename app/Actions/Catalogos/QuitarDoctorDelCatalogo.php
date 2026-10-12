<?php

namespace App\Actions\Catalogos;

use App\Clinica\Catalogos\UsoDeUnItemDelCatalogo;
use App\Models\Doctor;
use Illuminate\Database\Eloquent\Model;

/**
 * Quitar un médico referido del catálogo (WEB-8.1b): en uso = las referencias de las consultas
 * (`referencia.ceduladoctor`), buscadas en **todos los registros del médico** porque el catálogo de colegas se
 * resuelve por registros (`DoctoresDelMedico`). La regla común es la de `QuitarDelCatalogo`.
 */
final class QuitarDoctorDelCatalogo extends QuitarDelCatalogo
{
    protected function modelo(): string
    {
        return Doctor::class;
    }

    protected function tabla(): string
    {
        return 'doctores';
    }

    protected function uso(Model $fila, array $registros): UsoDeUnItemDelCatalogo
    {
        /** @var Doctor $fila */
        return UsoDeUnItemDelCatalogo::doctor(
            $registros !== [] ? $registros : [(string) $fila->reg_medico],
            (int) $fila->cedula,
        );
    }

    protected function nombre(Model $fila): string
    {
        /** @var Doctor $fila */
        $nombre = trim(trim((string) $fila->nombres) . ' ' . trim((string) $fila->apellidos));

        return $nombre !== '' ? $nombre : 'médico ' . (int) $fila->cedula;
    }
}
