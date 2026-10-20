<?php

namespace App\Clinica\Catalogos\Vistas;

use App\Actions\Catalogos\ReglasDelMotivoDeFactura;
use App\Models\MotivoFactura;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * La pantalla de **conceptos de pre-factura** (`/clinica/catalogos/motivos-factura`, F9): los conceptos facturables del
 * médico con su monto y su monto con seguro, un buscador y el diálogo de alta y edición. No consulta la base: recibe la
 * página ya leída y las URLs que resolvió el controlador.
 */
final class MotivosDeFacturaVista
{
    use ComparteElListadoDeCatalogo;

    /** @param  array<string,mixed>  $urls  `guardar` y `quitar` (una función de id) */
    public function __construct(
        private LengthAwarePaginator $filas,
        private string $buscar,
        private int $total,
        private ?MotivoFactura $editando,
        private string $base,
        private array $urls,
        private bool $abrirNuevo = false,
    ) {
    }

    protected function slug(): string
    {
        return 'motivos-factura';
    }

    public function subtitulo(): string
    {
        if ($this->hayFiltro()) {
            $total = $this->filas->total();

            return ($total === 0 ? 'Sin resultados' : $total . ($total === 1 ? ' concepto' : ' conceptos')) . ' para «' . $this->buscar . '».';
        }

        return $this->total . ($this->total === 1 ? ' concepto' : ' conceptos') . '. Son los que se eligen en la pre-factura de la consulta.';
    }

    /** @return array{icono:string, titulo:string, texto:string} */
    public function vacio(): array
    {
        if ($this->hayFiltro()) {
            return ['icono' => 'bi-search', 'titulo' => 'Ningún concepto coincide', 'texto' => 'Prueba con otra palabra o con el código. No importan las tildes ni las mayúsculas.'];
        }

        return ['icono' => 'bi-receipt', 'titulo' => 'Todavía no tienes conceptos', 'texto' => 'Crea el primero: es el que vas a elegir en la pre-factura de la consulta.'];
    }

    /** @return array<int,array<string,mixed>> */
    public function filas(): array
    {
        return collect($this->filas->items())->map(function (MotivoFactura $fila) {
            $nombre = trim((string) $fila->descripcion) !== '' ? trim((string) $fila->descripcion) : 'Sin nombre';

            return [
                'id' => (int) $fila->id,
                'nombre' => $nombre,
                'codigo' => trim((string) $fila->codigo),
                'monto' => $this->dinero($fila->monto),
                'montoSeg' => $fila->monto_seg === null ? null : $this->dinero($fila->monto_seg),
                'urlEditar' => $this->url(['editar' => $fila->id]),
                'urlQuitar' => ($this->urls['quitar'])((int) $fila->id),
                'avisoQuitar' => '¿Quitar «' . $nombre . '» del catálogo? Si alguna pre-factura lo tiene, no se borra y te digo dónde.',
            ];
        })->all();
    }

    private function dinero($valor): string
    {
        return number_format((float) $valor, 2, ',', '.');
    }

    // ------------------------------------------------------------------------------------------ El diálogo

    public function tituloDelFormulario(): string
    {
        return $this->editando() ? 'Editar el concepto' : 'Nuevo concepto';
    }

    public function botonGuardar(): string
    {
        return $this->editando() ? 'Guardar los cambios' : 'Crear el concepto';
    }

    public function codigoEditando(): ?string
    {
        return $this->editando() ? trim((string) $this->editando->codigo) : null;
    }

    public function largoNombre(): int
    {
        return ReglasDelMotivoDeFactura::LARGO_DESCRIPCION;
    }

    public function nombre(): string
    {
        return (string) old('descripcion', $this->editando?->descripcion ?? '');
    }

    public function monto(): string
    {
        return $this->numero(old('monto', $this->editando?->monto));
    }

    public function montoConSeguro(): string
    {
        return $this->numero(old('monto_seg', $this->editando?->monto_seg));
    }

    /** Un número para el `<input type=number>`: punto decimal, sin ceros de más. */
    private function numero($valor): string
    {
        if ($valor === null || $valor === '') {
            return '';
        }

        return is_numeric($valor) ? rtrim(rtrim(number_format((float) $valor, 2, '.', ''), '0'), '.') : (string) $valor;
    }
}
