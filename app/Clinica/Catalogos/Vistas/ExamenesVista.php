<?php

namespace App\Clinica\Catalogos\Vistas;

use App\Actions\Catalogos\CrearExamenEnElCatalogo;
use App\Clinica\Catalogos\ExamenesDelCatalogo;
use App\Clinica\Pacientes\CamposDePaciente;
use App\Models\Examen;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * La pantalla de **exámenes** del catálogo (`/clinica/catalogos/examenes`, WEB-8.1b): los exámenes y estudios del médico
 * con su tipo, un buscador, el filtro por tipo y el diálogo de alta y edición. Como todo ViewModel de la web clínica no
 * consulta la base: recibe la página ya leída y las URLs que resolvió el controlador.
 *
 * El diálogo es uno para alta y edición. El **tipo** es un `<select>` con los tipos del médico; un examen cuyo tipo no
 * está en el catálogo (el legado trae códigos sueltos) lo conserva como una opción aparte, para que editarlo no se lo borre.
 */
final class ExamenesVista
{
    use ComparteElListadoDeCatalogo;

    /**
     * @param  array<string,string>  $tipos  los tipos del médico, `código => nombre`
     * @param  string  $tipo  el filtro activo: un código, `ExamenesDelCatalogo::SIN_TIPO` o vacío
     * @param  array<string,mixed>  $urls  `guardar` y `quitar` (una función de id)
     */
    public function __construct(
        private LengthAwarePaginator $filas,
        private string $buscar,
        private string $tipo,
        private array $tipos,
        private int $total,
        private ?Examen $editando,
        private string $base,
        private array $urls,
        private bool $abrirNuevo = false,
    ) {
    }

    protected function slug(): string
    {
        return 'examenes';
    }

    protected function filtros(): array
    {
        return $this->tipo === '' ? [] : ['tipo' => $this->tipo];
    }

    public function subtitulo(): string
    {
        if ($this->hayFiltro()) {
            $total = $this->filas->total();

            return ($total === 0 ? 'Sin resultados' : $total . ($total === 1 ? ' examen' : ' exámenes'))
                . ($this->buscar !== '' ? ' para «' . $this->buscar . '»' : '')
                . ($this->tipo !== '' ? ' de ' . $this->nombreDelFiltro() : '') . '.';
        }

        return $this->total . ($this->total === 1 ? ' examen' : ' exámenes') . '. Son los que se piden en una consulta.';
    }

    /** @return array{icono:string, titulo:string, texto:string} */
    public function vacio(): array
    {
        if ($this->hayFiltro()) {
            return [
                'icono' => 'bi-search',
                'titulo' => 'Ningún examen coincide',
                'texto' => 'Prueba con otra palabra, con el código o con otro tipo. No importan las tildes ni las mayúsculas.',
            ];
        }

        return [
            'icono' => 'bi-eyedropper',
            'titulo' => 'Todavía no tienes exámenes',
            'texto' => 'Crea el primero: es el que vas a elegir al pedir un examen en la consulta.',
        ];
    }

    /** ¿El médico tiene tipos para elegir? Sin ellos el examen se crea sin tipo. */
    public function hayTipos(): bool
    {
        return $this->tipos !== [];
    }

    public function tipoActivo(): string
    {
        return $this->tipo;
    }

    /**
     * Las opciones del filtro: todos, cada tipo y los que no tienen tipo.
     *
     * @return array<int,array{valor:string, texto:string}>
     */
    public function opcionesDelFiltro(): array
    {
        $opciones = [['valor' => '', 'texto' => 'Todos los tipos']];

        foreach ($this->tipos as $codigo => $nombre) {
            $opciones[] = ['valor' => (string) $codigo, 'texto' => $nombre];
        }

        $opciones[] = ['valor' => ExamenesDelCatalogo::SIN_TIPO, 'texto' => 'Sin tipo'];

        return $opciones;
    }

    private function nombreDelFiltro(): string
    {
        if ($this->tipo === ExamenesDelCatalogo::SIN_TIPO) {
            return 'los que no tienen tipo';
        }

        return 'tipo «' . ($this->tipos[$this->tipo] ?? $this->tipo) . '»';
    }

    /** @return array<int,array<string,mixed>> */
    public function filas(): array
    {
        return collect($this->filas->items())->map(fn (Examen $fila) => $this->fila($fila))->all();
    }

    /** @return array<string,mixed> */
    private function fila(Examen $fila): array
    {
        $nombre = trim((string) $fila->examen) !== '' ? trim((string) $fila->examen) : 'Sin nombre';
        $repetidos = (int) $fila->getAttribute('repetidos');
        $codigoDelTipo = CamposDePaciente::texto($fila->codetipo);
        $tipo = $fila->getAttribute('tipo_nombre');

        return [
            'id' => (int) $fila->id,
            'nombre' => $nombre,
            'codigo' => trim((string) $fila->codeexamen),
            'tipo' => $tipo ?? ($codigoDelTipo === null ? null : 'Tipo ' . $codigoDelTipo . ' (no está en el catálogo)'),
            'repetido' => $repetidos > 1 ? 'Hay ' . $repetidos . ' con este nombre y tipo' : null,
            'urlEditar' => $this->url(['editar' => $fila->id]),
            'urlQuitar' => ($this->urls['quitar'])((int) $fila->id),
            'avisoQuitar' => '¿Quitar «' . $nombre . '» del catálogo? Si alguna consulta lo tiene pedido, no se borra y te digo dónde.',
        ];
    }

    // ------------------------------------------------------------------------------------------ El diálogo

    public function tituloDelFormulario(): string
    {
        return $this->editando() ? 'Editar el examen' : 'Nuevo examen';
    }

    public function botonGuardar(): string
    {
        return $this->editando() ? 'Guardar los cambios' : 'Crear el examen';
    }

    public function codigoEditando(): ?string
    {
        return $this->editando() ? trim((string) $this->editando->codeexamen) : null;
    }

    public function largoNombre(): int
    {
        return CrearExamenEnElCatalogo::LARGO_NOMBRE;
    }

    /** El nombre: lo que quedó de un `POST` que falló o el del examen que se edita. */
    public function nombre(): string
    {
        $valor = old('examen', $this->editando?->examen);

        return $valor === null ? '' : (string) $valor;
    }

    /**
     * El tipo seleccionado en el formulario: el de un `POST` que falló, el del examen que se edita o, en un alta
     * abierta desde un filtro de tipo, ese tipo.
     */
    public function tipoSeleccionado(): string
    {
        if (old('_catalogo') !== null) {
            return (string) old('codetipo', '');
        }

        if ($this->editando()) {
            return (string) CamposDePaciente::texto($this->editando->codetipo);
        }

        return isset($this->tipos[$this->tipo]) ? $this->tipo : '';
    }

    /**
     * Las opciones del `<select>` del formulario: sin tipo, cada tipo del catálogo y —si el examen que se edita tiene
     * un código que el catálogo no trae— ese código, para no perderlo.
     *
     * @return array<int,array{valor:string, texto:string}>
     */
    public function opcionesDeTipo(): array
    {
        $opciones = [['valor' => '', 'texto' => 'Sin tipo']];

        foreach ($this->tipos as $codigo => $nombre) {
            $opciones[] = ['valor' => (string) $codigo, 'texto' => $nombre];
        }

        $actual = $this->tipoSeleccionado();

        if ($actual !== '' && ! isset($this->tipos[$actual])) {
            $opciones[] = ['valor' => $actual, 'texto' => 'Tipo ' . $actual . ' (no está en el catálogo)'];
        }

        return $opciones;
    }
}
