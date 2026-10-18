<?php

namespace App\Clinica\Catalogos\Vistas;

use App\Actions\Catalogos\GuardarDietaDelCatalogo;
use App\Models\Dieta;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

/**
 * La pantalla de **dietas del médico** (`/clinica/catalogos/dietas`, WEB-12.4b): las dietas de pediatría (`dietas`) con su buscador y el diálogo de alta y
 * edición. Lo que se guarda acá es lo que la pestaña *Alimentación* de la consulta ofrece para copiar. No consulta la base: recibe la página ya leída y
 * las URLs que resolvió el controlador. El **código** lo da el servidor y no se edita.
 */
final class DietasCatalogoVista
{
    use ComparteElListadoDeCatalogo;

    /** @param  array<string,mixed>  $urls  `guardar` y `quitar` (una función de id) */
    public function __construct(
        private LengthAwarePaginator $filas,
        private string $buscar,
        private int $total,
        private ?Dieta $editando,
        private string $base,
        private array $urls,
        private bool $abrirNuevo = false,
    ) {
    }

    protected function slug(): string
    {
        return 'dietas';
    }

    public function subtitulo(): string
    {
        if ($this->hayFiltro()) {
            $total = $this->filas->total();

            return ($total === 0 ? 'Sin resultados' : $total . ($total === 1 ? ' dieta' : ' dietas')) . ' para «' . $this->buscar . '».';
        }

        return $this->total . ($this->total === 1 ? ' dieta' : ' dietas') . '. Son los textos de alimentación que se copian a la consulta del niño.';
    }

    /** @return array{icono:string, titulo:string, texto:string} */
    public function vacio(): array
    {
        if ($this->hayFiltro()) {
            return ['icono' => 'bi-search', 'titulo' => 'Ninguna dieta coincide', 'texto' => 'Prueba con otra palabra o con el código. No importan las tildes ni las mayúsculas.'];
        }

        return [
            'icono' => 'bi-egg-fried',
            'titulo' => 'Todavía no tienes dietas',
            'texto' => 'Crea la primera: es un texto de alimentación que vas a poder copiar con un clic en la pestaña Alimentación de la consulta.',
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function filas(): array
    {
        return collect($this->filas->items())->map(fn (Dieta $fila) => $this->fila($fila))->all();
    }

    /** @return array<string,mixed> */
    private function fila(Dieta $fila): array
    {
        $titulo = trim((string) $fila->dietas) !== '' ? trim((string) $fila->dietas) : 'Sin título';
        $texto = trim((string) preg_replace('/\s+/u', ' ', (string) $fila->descripcion));
        $texto = strtolower($texto) === 'none' ? '' : $texto;

        return [
            'id' => (int) $fila->id,
            'titulo' => $titulo,
            'resumen' => $texto === '' ? null : Str::limit($texto, 160),
            'codigo' => trim((string) $fila->cod_dieta),
            'calorias' => $this->calorias($fila->calorias),
            'sinTexto' => $texto === '',
            'urlEditar' => $this->url(['editar' => $fila->id]),
            'urlQuitar' => ($this->urls['quitar'])((int) $fila->id),
            'avisoQuitar' => '¿Quitar la dieta «' . $titulo . '»? Lo que ya escribiste con ella en las consultas no cambia.',
        ];
    }

    private function calorias($valor): ?string
    {
        if ($valor === null || (float) $valor <= 0) {
            return null;
        }

        return rtrim(rtrim(number_format((float) $valor, 2, ',', '.'), '0'), ',') . ' kcal';
    }

    // ------------------------------------------------------------------------------------------ El diálogo

    public function tituloDelFormulario(): string
    {
        return $this->editando() ? 'Editar la dieta' : 'Nueva dieta';
    }

    public function botonGuardar(): string
    {
        return $this->editando() ? 'Guardar los cambios' : 'Crear la dieta';
    }

    public function codigoEditando(): ?string
    {
        return $this->editando() ? trim((string) $this->editando->cod_dieta) : null;
    }

    public function largoTitulo(): int
    {
        return GuardarDietaDelCatalogo::LARGO_TITULO;
    }

    public function largoTexto(): int
    {
        return GuardarDietaDelCatalogo::LARGO_TEXTO;
    }

    public function tituloEscrito(): string
    {
        return (string) old('dietas', $this->editando?->dietas ?? '');
    }

    public function textoEscrito(): string
    {
        $texto = (string) old('descripcion', $this->editando?->descripcion ?? '');

        return strtolower(trim($texto)) === 'none' ? '' : $texto;
    }

    public function caloriasEscritas(): string
    {
        if (old('calorias') !== null) {
            return (string) old('calorias');
        }

        $valor = $this->editando?->calorias;

        return $valor === null || (float) $valor <= 0 ? '' : rtrim(rtrim(number_format((float) $valor, 2, ',', ''), '0'), ',');
    }
}
