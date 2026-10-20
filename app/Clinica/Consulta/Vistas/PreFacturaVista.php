<?php

namespace App\Clinica\Consulta\Vistas;

/**
 * **La pre-factura de la consulta** (F9): los conceptos que se le cobrarán, con su cantidad, su monto y el total.
 *
 * Recibe lo ya leído (`PreFacturaDeLaConsulta`) y devuelve los textos y las URLs que pinta `_prefactura`. Los montos
 * se muestran con coma decimal y punto de miles, como el resto de la web (`150,00`).
 */
final class PreFacturaVista
{
    /**
     * @param  array<int,array{codigo:string, descripcion:string, cantidad:float, monto:float, subtotal:float}>  $items
     * @param  array<int,array{codigo:string, descripcion:string, monto:float, monto_seg:?float}>  $catalogo
     */
    public function __construct(
        private array $items,
        private array $catalogo,
        private float $total,
        private string $urlGuardar,
        private string $urlQuitar,
    ) {
    }

    public static function monto(float $valor): string
    {
        return number_format($valor, 2, ',', '.');
    }

    /** La cantidad sin ceros de más: `1`, `2,5`. */
    public static function cantidad(float $valor): string
    {
        return rtrim(rtrim(number_format($valor, 2, ',', ''), '0'), ',');
    }

    /** @return array<int,array{codigo:string, descripcion:string, cantidad:string, cantidadValor:string, monto:string, montoValor:string, subtotal:string}> */
    public function items(): array
    {
        return array_map(fn (array $item) => [
            'codigo'        => $item['codigo'],
            'descripcion'   => $item['descripcion'],
            'cantidad'      => self::cantidad($item['cantidad']),
            // El valor del `<input type=number>`: con punto, que es lo que entiende el navegador.
            'cantidadValor' => rtrim(rtrim(number_format($item['cantidad'], 2, '.', ''), '0'), '.'),
            'monto'         => self::monto($item['monto']),
            'montoValor'    => number_format($item['monto'], 2, '.', ''),
            'subtotal'      => self::monto($item['subtotal']),
        ], $this->items);
    }

    public function tieneItems(): bool
    {
        return $this->items !== [];
    }

    /** @return array<int,array{valor:string, texto:string, monto:string}> */
    public function opciones(): array
    {
        return array_map(fn (array $concepto) => [
            'valor' => $concepto['codigo'],
            'texto' => $concepto['descripcion'] . ' — ' . self::monto($concepto['monto'])
                . ($concepto['monto_seg'] !== null && $concepto['monto_seg'] > 0
                    ? ' (seguro: ' . self::monto($concepto['monto_seg']) . ')'
                    : ''),
            'monto' => number_format($concepto['monto'], 2, '.', ''),
        ], $this->catalogo);
    }

    public function tieneCatalogo(): bool
    {
        return $this->catalogo !== [];
    }

    public function total(): string
    {
        return self::monto($this->total);
    }

    public function urlGuardar(): string
    {
        return $this->urlGuardar;
    }

    public function urlQuitar(): string
    {
        return $this->urlQuitar;
    }

    public function estado(): array
    {
        $cuantos = count($this->items);

        return $cuantos > 0
            ? ['tono' => 'exito', 'icono' => 'bi-check2-circle', 'texto' => $cuantos === 1 ? '1 ítem' : $cuantos . ' ítems',
                'titulo' => 'La consulta ya tiene conceptos en la pre-factura']
            : ['tono' => 'neutro', 'icono' => 'bi-dash-circle', 'texto' => 'Sin cargar',
                'titulo' => 'Todavía no hay conceptos en la pre-factura de esta consulta'];
    }

    public function ayuda(): string
    {
        return 'Los conceptos que se le cobrarán al paciente por esta consulta, del catálogo del consultorio. El monto '
            . 'propuesto es el del catálogo y se puede corregir; cargar otra vez un concepto que ya está corrige su '
            . 'cantidad y su monto.';
    }
}
