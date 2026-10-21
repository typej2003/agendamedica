<?php

namespace App\Actions\Catalogos;

use App\Clinica\Catalogos\UsoDeUnItemDelCatalogo;
use App\Models\MotivoFactura;
use Illuminate\Database\Eloquent\Model;

/** Quitar un concepto facturable: en uso = alguna pre-factura (`his_con_pre_factura`) lo nombra por su código. */
final class QuitarMotivoDeFactura extends QuitarDelCatalogo
{
    protected function modelo(): string
    {
        return MotivoFactura::class;
    }

    protected function tabla(): string
    {
        return 'motivo_factura';
    }

    protected function uso(Model $fila, array $registros): UsoDeUnItemDelCatalogo
    {
        /** @var MotivoFactura $fila */
        return UsoDeUnItemDelCatalogo::motivoDeFactura($registros, (string) $fila->codigo);
    }

    protected function nombre(Model $fila): string
    {
        /** @var MotivoFactura $fila */
        $nombre = trim((string) $fila->descripcion);

        return $nombre !== '' ? $nombre : 'concepto ' . trim((string) $fila->codigo);
    }
}
