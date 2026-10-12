<?php

namespace App\Clinica\Catalogos\Vistas;

use App\Clinica\Catalogos\DefinicionDeCatalogoSimple;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;

/**
 * La pantalla de un catálogo de **"código + texto"** (WEB-8.1b): diagnósticos, motivos de consulta y tipos de examen
 * (`/clinica/catalogos/diagnosticos`, `…/motivos-consulta`, `…/tipos-examen`). Como todo ViewModel de la web clínica no
 * consulta la base: recibe la página ya leída y las URLs que resolvió el controlador.
 *
 * El diálogo es **uno** para alta y edición, y el formulario toma sus valores de `old()` (un `POST` que falló) o de la fila
 * que se edita (`?editar=`). La baja es "quitar": el servidor decide si se puede (si la fila está en uso no se borra y el
 * aviso dice dónde), así que la pantalla sólo pide la confirmación.
 */
final class CatalogoSimpleVista
{
    use ComparteElListadoDeCatalogo;

    /**
     * @param  string  $base  la URL de la pantalla sin parámetros
     * @param  int  $total  cuántas filas tiene el catálogo (sin búsqueda): decide el estado vacío
     * @param  array<string,mixed>  $urls  `guardar` y `quitar` (una función de id, que arma el controlador con `route()`)
     */
    public function __construct(
        private DefinicionDeCatalogoSimple $def,
        private LengthAwarePaginator $filas,
        private string $buscar,
        private int $total,
        private ?Model $editando,
        private string $base,
        private array $urls,
        private bool $abrirNuevo = false,
    ) {
    }

    protected function slug(): string
    {
        return $this->def->slug;
    }

    public function titulo(): string
    {
        return $this->def->titulo;
    }

    public function textoNuevo(): string
    {
        return $this->def->nuevo;
    }

    public function icono(): string
    {
        return $this->def->icono;
    }

    public function placeholder(): string
    {
        return $this->def->placeholder;
    }

    public function etiquetaDeBuscar(): string
    {
        return 'Buscar ' . $this->def->singular;
    }

    public function subtitulo(): string
    {
        if ($this->hayBusqueda()) {
            $total = $this->filas->total();

            return ($total === 0 ? 'Sin resultados' : $total . ' ' . ($total === 1 ? $this->def->singular : $this->def->plural))
                . ' para «' . $this->buscar . '».';
        }

        return $this->total . ' ' . ($this->total === 1 ? $this->def->singular : $this->def->plural) . '. ' . $this->def->paraQueSirve;
    }

    /**
     * El estado vacío distingue "no hay nada todavía" de "tu búsqueda no encontró nada" (guía de UI §4).
     *
     * @return array{icono:string, titulo:string, texto:string}
     */
    public function vacio(): array
    {
        if ($this->hayBusqueda()) {
            return [
                'icono' => 'bi-search',
                'titulo' => 'Ningún ' . $this->def->singular . ' coincide con «' . $this->buscar . '»',
                'texto' => 'Prueba con otra palabra o con el código. No importan las tildes ni las mayúsculas.',
            ];
        }

        return [
            'icono' => $this->def->icono,
            'titulo' => 'Todavía no tienes ' . $this->def->plural,
            'texto' => 'Crea el primero: ' . lcfirst($this->def->paraQueSirve),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public function filas(): array
    {
        return collect($this->filas->items())->map(fn (Model $fila) => $this->fila($fila))->all();
    }

    /** @return array<string,mixed> */
    private function fila(Model $fila): array
    {
        $texto = trim((string) $fila->getAttribute($this->def->columnaDelTexto));
        $nombre = $texto !== '' ? $texto : 'Sin nombre';
        $repetidos = (int) $fila->getAttribute('repetidos');

        return [
            'id' => (int) $fila->getKey(),
            'nombre' => $nombre,
            'codigo' => trim((string) $fila->getAttribute($this->def->columnaDelCodigo)),
            'repetido' => $repetidos > 1 ? 'Hay ' . $repetidos . ' con este nombre' : null,
            'urlEditar' => $this->url(['editar' => $fila->getKey()]),
            'urlQuitar' => ($this->urls['quitar'])((int) $fila->getKey()),
            'avisoQuitar' => '¿Quitar «' . $nombre . '» del catálogo? ' . $this->def->avisoDeUso,
        ];
    }

    // ------------------------------------------------------------------------------------------ El diálogo

    public function tituloDelFormulario(): string
    {
        return $this->editando() ? 'Editar ' . $this->def->singular : $this->def->nuevo;
    }

    public function botonGuardar(): string
    {
        return $this->editando() ? 'Guardar los cambios' : 'Crear';
    }

    /** El código de la fila que se edita (el alta lo da el servidor y no se muestra). */
    public function codigoEditando(): ?string
    {
        return $this->editando() ? trim((string) $this->editando->getAttribute($this->def->columnaDelCodigo)) : null;
    }

    public function etiquetaDelTexto(): string
    {
        return $this->def->etiquetaDelTexto;
    }

    public function ayudaDelTexto(): string
    {
        return $this->def->ayudaDelTexto . ' Hasta ' . $this->def->largo . ' caracteres.';
    }

    public function largo(): int
    {
        return $this->def->largo;
    }

    /** El texto del campo: lo que quedó de un `POST` que falló o el de la fila que se edita. */
    public function texto(): string
    {
        $valor = old('descripcion', $this->editando?->getAttribute($this->def->columnaDelTexto));

        return $valor === null ? '' : (string) $valor;
    }
}
