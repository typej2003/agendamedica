<?php

namespace App\Clinica\Catalogos\Vistas;

use App\Actions\Catalogos\ReglasDeLaPlantillaDeTexto;
use App\Clinica\Catalogos\TiposDePlantillaDeTexto;
use App\Models\FormatoPrint;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

/**
 * La pantalla de **plantillas de texto** (`/clinica/catalogos/plantillas-texto`, WEB-8.4): las plantillas de la evolución, del informe
 * médico, de las dietas… del médico (`formato_print`) con su buscador, el filtro por tipo y el diálogo de alta y edición. Como todo
 * ViewModel de la web clínica no consulta la base: recibe la página ya leída y las URLs que resolvió el controlador.
 *
 * El diálogo es uno para alta y edición. El **tipo** es un `<select>` agrupado: primero los que la consulta de la web ya ofrece,
 * después los otros del escritorio; un tipo que el legado trajo y no está en la lista se conserva como una opción aparte para que
 * editar la plantilla no se lo cambie. El **código** lo da el servidor y no se edita.
 */
final class PlantillasDeTextoVista
{
    use ComparteElListadoDeCatalogo;

    /**
     * @param  array<string,int>  $tipos  los tipos del médico con su cantidad (`código => cantidad`)
     * @param  string  $tipo  el filtro activo: un código o vacío
     * @param  array<string,mixed>  $urls  `guardar` y `quitar` (una función de id)
     */
    public function __construct(
        private LengthAwarePaginator $filas,
        private string $buscar,
        private string $tipo,
        private array $tipos,
        private int $total,
        private ?FormatoPrint $editando,
        private string $base,
        private array $urls,
        private bool $abrirNuevo = false,
    ) {
    }

    protected function slug(): string
    {
        return 'plantillas-texto';
    }

    protected function filtros(): array
    {
        // El filtro del listado se llama `filtro` y no `tipo`: `tipo` es el campo del formulario (el tipo de la plantilla).
        return $this->tipo === '' ? [] : ['filtro' => $this->tipo];
    }

    public function subtitulo(): string
    {
        if ($this->hayFiltro()) {
            $total = $this->filas->total();

            return ($total === 0 ? 'Sin resultados' : $total . ($total === 1 ? ' plantilla' : ' plantillas'))
                . ($this->buscar !== '' ? ' para «' . $this->buscar . '»' : '')
                . ($this->tipo !== '' ? ' de tipo «' . TiposDePlantillaDeTexto::nombre($this->tipo) . '»' : '') . '.';
        }

        return $this->total . ($this->total === 1 ? ' plantilla' : ' plantillas') . '. Son los textos que se copian al escribir la evolución, el informe o las dietas en la consulta.';
    }

    /** @return array{icono:string, titulo:string, texto:string} */
    public function vacio(): array
    {
        if ($this->hayFiltro()) {
            return [
                'icono' => 'bi-search',
                'titulo' => 'Ninguna plantilla coincide',
                'texto' => 'Prueba con otra palabra, con el código o con otro tipo. No importan las tildes ni las mayúsculas.',
            ];
        }

        return [
            'icono' => 'bi-file-earmark-text',
            'titulo' => 'Todavía no tienes plantillas de texto',
            'texto' => 'Crea la primera: es un texto que vas a poder copiar con un clic al escribir la evolución, el informe o las dietas.',
        ];
    }

    public function tipoActivo(): string
    {
        return $this->tipo;
    }

    /**
     * Las opciones del filtro: todos y cada tipo del médico con su cantidad.
     *
     * @return array<int,array{valor:string, texto:string}>
     */
    public function opcionesDelFiltro(): array
    {
        $opciones = [['valor' => '', 'texto' => 'Todos los tipos']];

        foreach ($this->tipos as $codigo => $cantidad) {
            $opciones[] = ['valor' => (string) $codigo, 'texto' => TiposDePlantillaDeTexto::nombre($codigo) . ' (' . $cantidad . ')'];
        }

        return $opciones;
    }

    /** @return array<int,array<string,mixed>> */
    public function filas(): array
    {
        return collect($this->filas->items())->map(fn (FormatoPrint $fila) => $this->fila($fila))->all();
    }

    /** @return array<string,mixed> */
    private function fila(FormatoPrint $fila): array
    {
        $titulo = trim((string) $fila->titulo) !== '' ? trim((string) $fila->titulo) : 'Sin título';
        $texto = trim((string) preg_replace('/\s+/u', ' ', (string) $fila->descripcion));
        $codigoDelTipo = trim((string) $fila->tipo);

        return [
            'id' => (int) $fila->id,
            'titulo' => $titulo,
            'resumen' => $texto === '' ? null : Str::limit($texto, 160),
            'tipo' => TiposDePlantillaDeTexto::nombre($codigoDelTipo),
            'tipoEnLaWeb' => TiposDePlantillaDeTexto::seUsaEnLaWeb($codigoDelTipo),
            'codigo' => trim((string) $fila->codigo),
            'sinTexto' => $texto === '',
            'urlEditar' => $this->url(['editar' => $fila->id]),
            'urlQuitar' => ($this->urls['quitar'])((int) $fila->id),
            'avisoQuitar' => '¿Quitar la plantilla «' . $titulo . '»? Lo que ya escribiste con ella en las consultas no cambia.',
        ];
    }

    // ------------------------------------------------------------------------------------------ El diálogo

    public function tituloDelFormulario(): string
    {
        return $this->editando() ? 'Editar la plantilla' : 'Nueva plantilla de texto';
    }

    public function botonGuardar(): string
    {
        return $this->editando() ? 'Guardar los cambios' : 'Crear la plantilla';
    }

    public function codigoEditando(): ?string
    {
        return $this->editando() ? trim((string) $this->editando->codigo) : null;
    }

    public function largoTitulo(): int
    {
        return ReglasDeLaPlantillaDeTexto::LARGO_TITULO;
    }

    public function largoTexto(): int
    {
        return ReglasDeLaPlantillaDeTexto::LARGO_TEXTO;
    }

    /** El título: lo que quedó de un `POST` que falló o el de la plantilla que se edita. */
    public function tituloEscrito(): string
    {
        $valor = old('titulo', $this->editando?->titulo);

        return $valor === null ? '' : (string) $valor;
    }

    /** El texto: lo que quedó de un `POST` que falló o el de la plantilla que se edita. */
    public function textoEscrito(): string
    {
        $valor = old('descripcion', $this->editando?->descripcion);

        return $valor === null ? '' : (string) $valor;
    }

    /**
     * El tipo seleccionado en el formulario: el de un `POST` que falló, el de la plantilla que se edita o, en un alta abierta desde un
     * filtro de tipo, ese tipo.
     */
    public function tipoSeleccionado(): string
    {
        if (old('_catalogo') !== null) {
            return (string) old('tipo', '');
        }

        if ($this->editando()) {
            return trim((string) $this->editando->tipo);
        }

        return $this->tipo;
    }

    /**
     * Los grupos del `<select>` del tipo. El primero lleva los tipos que la consulta de la web ya ofrece; el segundo, los otros del
     * escritorio; y —si la plantilla que se edita tiene un código que no está en la lista— un último con ese código, para no
     * perderlo.
     *
     * @return array<int,array{grupo:string, opciones:array<int,array{valor:string, texto:string}>}>
     */
    public function gruposDeTipo(): array
    {
        $enLaWeb = [];
        $otros = [];

        foreach (TiposDePlantillaDeTexto::nombres() as $codigo => $nombre) {
            $opcion = ['valor' => $codigo, 'texto' => $nombre];

            if (TiposDePlantillaDeTexto::seUsaEnLaWeb($codigo)) {
                $enLaWeb[] = $opcion;
            } else {
                $otros[] = $opcion;
            }
        }

        $grupos = [
            ['grupo' => 'Se ofrecen en la consulta de la web', 'opciones' => $enLaWeb],
            ['grupo' => 'Otros formatos del escritorio (la consulta de la web todavía no los ofrece)', 'opciones' => $otros],
        ];

        $actual = $this->tipoSeleccionado();

        if ($actual !== '' && ! TiposDePlantillaDeTexto::conocido($actual)) {
            $grupos[] = ['grupo' => 'Del legado', 'opciones' => [['valor' => $actual, 'texto' => 'Tipo ' . $actual . ' (no está en la lista)']]];
        }

        return $grupos;
    }
}
