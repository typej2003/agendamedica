<?php

namespace App\Clinica\Pacientes\Vistas;

use App\Clinica\Pacientes\CamposDePaciente;
use App\Clinica\Pacientes\FiltroDePacientes;
use App\Support\FechaClinica;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Lo que muestra el listado de pacientes (`/clinica/pacientes`): textos ya formateados, el estado de
 * cada historia, el filtro "Sin historia" (WEB-3.3) y el mensaje de "no hay nada". La vista solo lo
 * recorre.
 *
 * Cada campo pasa por `CamposDePaciente`: el legado guarda la cédula ausente como `S.C.` y el número de
 * historia como texto que puede venir vacío, y la guía de UI pide `—`/"Sin cédula" cuando el dato no está.
 */
final class ListadoDePacientesVista
{
    /**
     * @param  array{todos:int, sinHistoria:int, conHistoria:int}  $conteos  los del segmentado, con la
     *         búsqueda ya aplicada: salen de `PacientesDelConsultorio::conteos()` y **no** se calculan acá
     * @param  string|null  $base  la URL del listado sin parámetros (`route('clinica.pacientes')`), que
     *         arma el controlador: el ViewModel se prueba sin contenedor y no llama a `route()`
     */
    public function __construct(
        private LengthAwarePaginator $pacientes,
        private string $buscar = '',
        private string $filtro = FiltroDePacientes::TODOS,
        private array $conteos = ['todos' => 0, 'sinHistoria' => 0, 'conHistoria' => 0],
        private ?string $base = null,
    ) {
    }

    public function buscar(): string
    {
        return $this->buscar;
    }

    public function hayBusqueda(): bool
    {
        return $this->buscar !== '';
    }

    /** El valor del filtro con el que se está mirando el listado (`todos`, `sin-historia`, `con-historia`). */
    public function filtro(): string
    {
        return $this->filtro;
    }

    /** Mirando sólo los que no tienen historia: la lista de trabajo de la secretaría. */
    public function viendoSinHistoria(): bool
    {
        return FiltroDePacientes::esSinHistoria($this->filtro);
    }

    /** ¿Hay algún filtro puesto (dejó de ser "Todos")? Lo usa el estado vacío para ofrecer la salida. */
    public function hayFiltro(): bool
    {
        return $this->filtro !== FiltroDePacientes::TODOS;
    }

    /**
     * Las opciones del `<x-ui.segmentado>`: Todos / Sin historia / Con historia, en ese orden.
     *
     * Las etiquetas llevan el **conteo** (patrón de Sedes: "Activas (3)"), que es el dato que decide si
     * vale la pena entrar al filtro sin tener que hacer clic. La búsqueda viaja en cada URL para no
     * perderla al cambiar de segmento: `url()` la conserva, y "Todos" manda `historia => null` para
     * volver al listado sin filtro.
     *
     * @return array<int,array{texto:string, url:string, activa:bool}>
     */
    public function filtros(): array
    {
        return [
            [
                'texto'  => 'Todos (' . $this->conteos['todos'] . ')',
                'url'    => $this->url(['historia' => null]),
                'activa' => $this->filtro === FiltroDePacientes::TODOS,
            ],
            [
                'texto'  => 'Sin historia (' . $this->conteos['sinHistoria'] . ')',
                'url'    => $this->url(['historia' => FiltroDePacientes::SIN_HISTORIA]),
                'activa' => $this->viendoSinHistoria(),
            ],
            [
                'texto'  => 'Con historia (' . $this->conteos['conHistoria'] . ')',
                'url'    => $this->url(['historia' => FiltroDePacientes::CON_HISTORIA]),
                'activa' => FiltroDePacientes::esConHistoria($this->filtro),
            ],
        ];
    }

    public function paginador(): LengthAwarePaginator
    {
        return $this->pacientes;
    }

    /**
     * El listado sin búsqueda ni filtro: la salida del estado vacío ("Ver todos los pacientes" no puede
     * dejar puesto el filtro que dejó la tabla sin filas).
     */
    public function urlTodos(): string
    {
        return $this->base();
    }

    /**
     * La misma pantalla **sin la búsqueda escrita y con el filtro puesto**: a dónde vuelve "Limpiar" del
     * buscador. Sacar la búsqueda no puede cambiar de segmento (si no, limpiar "Sin historia" mostraría
     * la lista entera sin que nadie lo haya pedido).
     */
    public function urlLimpiarBusqueda(): string
    {
        return $this->url(['buscar' => '']);
    }

    public function haySobrantes(): bool
    {
        return $this->pacientes->hasPages();
    }

    /**
     * El subtítulo dice las tres cosas que pueden estar pasando a la vez: cuántos hay, qué búsqueda está
     * puesta y qué filtro. "Sin historia" se nombra siempre que esté puesto, aunque no haya búsqueda:
     * es la lista de trabajo de la secretaría y el título de la página no la menciona.
     */
    public function subtitulo(): string
    {
        $total = $this->pacientes->total();
        $partes = [];

        if ($this->hayBusqueda()) {
            $partes[] = $total === 0
                ? 'Sin resultados para «' . $this->buscar . '»'
                : $total . ($total === 1 ? ' resultado' : ' resultados') . ' para «' . $this->buscar . '»';
        } else {
            $partes[] = $total . ($total === 1 ? ' paciente' : ' pacientes')
                . ($this->viendoSinHistoria() ? ' sin número de historia' : '');
        }

        if ($this->viendoSinHistoria()) {
            $partes[] = 'hay que asignarles el número antes de atenderlos';
        } elseif (FiltroDePacientes::esConHistoria($this->filtro)) {
            $partes[] = 'ya tienen su número de historia';
        }

        return implode(': ', $partes) . '.';
    }

    /** "Mostrando 1–25 de 134", o vacío cuando no hay filas. */
    public function rango(): string
    {
        if ($this->pacientes->total() === 0) {
            return '';
        }

        return 'Mostrando ' . $this->pacientes->firstItem() . '–' . $this->pacientes->lastItem()
            . ' de ' . $this->pacientes->total();
    }

    public function estaVacio(): bool
    {
        return $this->pacientes->total() === 0;
    }

    /**
     * El estado vacío, que distingue **tres** casos (guía de UI §4: "no hay nada todavía" no es lo mismo
     * que "tu búsqueda no encontró nada"): no hay pacientes en el consultorio, la búsqueda no encontró, o
     * el filtro "Sin historia" no tiene a quién llenarle la historia —que es una buena noticia—.
     *
     * @return array{icono:string, titulo:string, texto:string}
     */
    public function vacio(): array
    {
        if ($this->hayBusqueda()) {
            return ['icono' => 'bi-search', 'titulo' => 'Ningún paciente coincide con la búsqueda', 'texto' => 'Prueba con otro nombre, la cédula o el n.º de historia.'];
        }

        if ($this->viendoSinHistoria()) {
            return ['icono' => 'bi-journal-medical', 'titulo' => 'No hay pacientes sin historia', 'texto' => 'Todos los pacientes de este consultorio ya tienen su número de historia.'];
        }

        if (FiltroDePacientes::esConHistoria($this->filtro)) {
            return ['icono' => 'bi-journal-medical', 'titulo' => 'Todavía no hay pacientes con historia', 'texto' => 'Los pacientes aparecen aquí cuando se les asigna el número.'];
        }

        return ['icono' => 'bi-people', 'titulo' => 'Este consultorio todavía no tiene pacientes', 'texto' => 'Los pacientes aparecen aquí cuando se registran o se sincronizan.'];
    }

    /** @return array<int, array<string, mixed>> */
    public function filas(): array
    {
        return collect($this->pacientes->items())->map(fn ($paciente) => $this->fila($paciente))->all();
    }

    private function fila(object $paciente): array
    {
        $edad = FechaClinica::edad($paciente->fnacimiento);
        $cedula = CamposDePaciente::cedula($paciente->cedula);
        $historia = CamposDePaciente::numeroDeHistoria($paciente->numhistoria);

        return [
            'id'         => (int) $paciente->id,
            'url'        => route('clinica.pacientes.ver', $paciente->id),
            'nombre'     => CamposDePaciente::nombre($paciente->apellidos, $paciente->nombres),
            'cedula'     => $cedula !== null ? 'C.I. ' . $cedula : 'Sin cédula',
            'historia'   => $historia !== null
                ? ['tono' => 'marca', 'icono' => 'bi-journal-medical', 'texto' => 'Historia ' . $historia]
                : ['tono' => 'aviso', 'icono' => 'bi-exclamation-triangle', 'texto' => 'Sin historia'],
            'edad'       => $edad !== null ? $edad . ($edad === 1 ? ' año' : ' años') : null,
            'nacimiento' => FechaClinica::formato($paciente->fnacimiento),
            'telefono'   => CamposDePaciente::texto($paciente->telefono),
            'correo'     => CamposDePaciente::texto($paciente->email),
        ];
    }

    /**
     * La URL del listado con lo que hay que conservar: la búsqueda que ya estaba escrita y el filtro
     * actual, con lo que pida `$query` encima.
     *
     * `$query` decide **por presencia** y no por valor (`array_key_exists`), porque sus dos usos
     * necesitan distinguir "dejar lo que hay" de "sacarlo": `historia => null` es "volver a Todos" y
     * `buscar => ''` es "Limpiar la búsqueda" —que es justo lo que `http_build_query` no puede expresar
     * con un valor—. Las claves nulas o vacías no llegan a la barra del navegador.
     *
     * @param  array{buscar?:?string, historia?:?string}  $query
     */
    private function url(array $query): string
    {
        $parametros = array_filter([
            'buscar'   => array_key_exists('buscar', $query) ? $query['buscar'] : $this->buscar,
            'historia' => array_key_exists('historia', $query) ? $query['historia'] : $this->filtro,
        ], fn ($valor) => $valor !== null && $valor !== '' && $valor !== FiltroDePacientes::TODOS);

        $consulta = http_build_query($parametros);

        return $consulta === '' ? $this->base() : $this->base() . '?' . $consulta;
    }

    /** La URL del listado sin parámetros: la que el controlador resolvió con `route()`. */
    private function base(): string
    {
        return $this->base ?? '/clinica/pacientes';
    }
}
