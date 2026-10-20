<?php

namespace App\Clinica\Consulta;

use App\Models\Consulta;
use App\Models\MotivoFactura;
use App\Models\PreFacturaConsulta;
use Illuminate\Support\Collection;

/**
 * **La pre-factura de la consulta** (F9): el último ítem «Documentos» del panel en ginecología y en pediatría.
 *
 * El mapa del dato:
 *  - Los ítems viven en **`his_con_pre_factura`**, varias filas por consulta, con la clave de negocio
 *    `numhistoria` + `nroconsulta` + `codigo`: **un concepto por consulta**; cargarlo otra vez corrige su cantidad y su
 *    monto en el lugar, no duplica.
 *  - El concepto sale del catálogo **`motivo_factura`** del médico (todos sus `reg_medico`): `codigo` (`varchar(8)`),
 *    `descripcion` (`varchar(45)`), `monto` y `monto_seg`. No hay texto libre: un código que no está en el catálogo
 *    se rechaza.
 *  - El monto propuesto es el `monto` del catálogo y es editable por ítem; `monto_seg` se muestra al lado como
 *    referencia (la regla de cuándo aplica no está relevada del escritorio).
 *  - El total es Σ `cantidad × monto`; no se guarda en ninguna columna.
 *  - `id_registro` (`double NOT NULL`) es el correlativo del escritorio: la fila nueva lleva el mayor del médico + 1.
 *
 * No escribe `factura_cliente` ni toca `cola`: la pre-factura es el borrador de lo que se cobrará, no la factura.
 */
final class PreFacturaDeLaConsulta
{
    public const TABLA = 'his_con_pre_factura';

    /** Los largos del esquema del legado. */
    public const LARGO_CODIGO = 8;
    public const LARGO_DESCRIPCION = 45;

    /** Topes de lo que se acepta por ítem (cordura, no del esquema: las columnas son `double`). */
    public const CANTIDAD_MAXIMA = 9999;
    public const MONTO_MAXIMO = 999999999;

    /** @return Collection<int,PreFacturaConsulta> */
    public function de(Consulta $consulta): Collection
    {
        return PreFacturaConsulta::where('numhistoria', (int) $consulta->numhistoria)
            ->where('nroconsulta', (int) $consulta->nroconsulta)
            ->where('reg_medico', $consulta->reg_medico)
            ->orderBy('id_registro')
            ->orderBy('id')
            ->get();
    }

    public function fila(Consulta $consulta, string $codigo): ?PreFacturaConsulta
    {
        return PreFacturaConsulta::where('numhistoria', (int) $consulta->numhistoria)
            ->where('nroconsulta', (int) $consulta->nroconsulta)
            ->where('reg_medico', $consulta->reg_medico)
            ->where('codigo', $this->codigo($codigo))
            ->first();
    }

    /** El código normalizado: sin espacios a los lados y con el largo de la columna. */
    public function codigo(string $codigo): string
    {
        return mb_substr(trim($codigo), 0, self::LARGO_CODIGO);
    }

    /**
     * El catálogo del médico, **un concepto por código** (el de `id` más bajo si el legado trae repetidos), por
     * descripción.
     *
     * @param  array<int,string>  $registros  los `reg_medico` del médico
     * @return Collection<int,MotivoFactura>
     */
    public function catalogo(array $registros): Collection
    {
        if ($registros === []) {
            return collect();
        }

        return MotivoFactura::whereIn('reg_medico', $registros)
            ->orderBy('id')
            ->get()
            ->filter(fn (MotivoFactura $motivo) => trim((string) $motivo->codigo) !== '')
            ->unique(fn (MotivoFactura $motivo) => trim((string) $motivo->codigo))
            ->sortBy(fn (MotivoFactura $motivo) => mb_strtoupper(trim((string) $motivo->descripcion)))
            ->values();
    }

    /** @param  array<int,string>  $registros */
    public function concepto(array $registros, string $codigo): ?MotivoFactura
    {
        $codigo = $this->codigo($codigo);

        return $codigo === ''
            ? null
            : $this->catalogo($registros)->first(fn (MotivoFactura $motivo) => trim((string) $motivo->codigo) === $codigo);
    }

    /** @param  Collection<int,PreFacturaConsulta>  $items */
    public static function total(Collection $items): float
    {
        return round($items->sum(fn (PreFacturaConsulta $item) => self::subtotal($item)), 2);
    }

    public static function subtotal(PreFacturaConsulta $item): float
    {
        return round((float) ($item->cantidad ?? 0) * (float) ($item->monto ?? 0), 2);
    }

    /** El correlativo del escritorio para una fila nueva: el mayor del médico + 1. */
    public function siguienteRegistro(?string $regMedico): float
    {
        $mayor = PreFacturaConsulta::where('reg_medico', $regMedico)->lockForUpdate()->max('id_registro');

        return (float) floor((float) ($mayor ?? 0)) + 1;
    }
}
