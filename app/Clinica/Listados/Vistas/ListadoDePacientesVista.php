<?php

namespace App\Clinica\Listados\Vistas;

use App\Clinica\Listados\FiltroDePacientesPorRango;
use App\Clinica\Listados\ListadosDisponibles;
use App\Clinica\Pacientes\CamposDePaciente;
use App\Clinica\Pacientes\CatalogoDeSeguros;
use App\Clinica\Pacientes\Vistas\FormularioDePacienteVista;
use App\Support\FechaClinica;
use Illuminate\Support\Collection;

/**
 * **Lo que muestra el listado de pacientes por rango** (WEB-6.8): las ocho columnas de *Indicadores pacientes*
 * del escritorio (`w_reprotes_pacientes.srw`), ya formateadas, con los filtros que se están mirando y los
 * estados de la pantalla.
 *
 * Las columnas son las del legado y en su orden: **# Hist.**, **Nombre del paciente**, **Cédula**, **Edad**,
 * **E. civil**, **Sexo**, **F. ingreso** y **Empresa**. Con estas decisiones de la web:
 *
 *  - **# Hist.** es el número con ceros a la izquierda hasta cinco dígitos (`00012`), como el legado numera; un
 *    número que no es numérico se muestra tal cual.
 *  - **Nombre** es `Apellidos, Nombres`, como en toda la web (`CamposDePaciente::nombre()`), no el
 *    `nombres + ' ' + apellidos` del escritorio.
 *  - **Cédula** es `nacionalidad-cédula` (`V-12345678`); si el legado guardó su marcador de «sin cédula» no se
 *    inventa una (queda `—`).
 *  - **Edad** es la de `FechaClinica::edad()`, la misma que usa el filtro (que se traduce a fechas de
 *    nacimiento) y que el resto de las pantallas.
 *  - **E. civil** y **Sexo** salen de los catálogos de la ficha (`FormularioDePacienteVista`); un código que no
 *    esté ahí se muestra tal cual, no se pierde.
 *  - **Empresa** es el nombre del seguro/empresa del catálogo del consultorio (`CatalogoDeSeguros`), el código
 *    si el nombre no está, y `—` para `00` (PERSONAL) o vacío: la misma regla de la ficha y del listado de
 *    embarazos.
 *
 * **Sin totales ni cortes**: ninguno de los DataWindows del legado tiene `sum` ni `count`, así que tampoco hay
 * acá. Lo único que se cuenta es cuántos pacientes se están mirando, para el subtítulo.
 *
 * La clase no toca la base: el catálogo de seguros llega leído y las URLs las resuelve el controlador.
 */
final class ListadoDePacientesVista
{
    /** Los ceros a la izquierda con los que el legado numera las historias. */
    private const DIGITOS_DE_LA_HISTORIA = 5;

    /**
     * @param  Collection<int,object>  $pacientes  las filas de `PacientesPorRango::filas()`
     * @param  Collection<int,object>  $seguros  el catálogo del consultorio (`codesegemp`, `nombre`)
     * @param  string  $urlBase  el listado sin filtros (`route('clinica.listados')`)
     * @param  string  $urlImprimir  la hoja impresa con **los mismos filtros** que la pantalla
     * @param  callable(int):string|null  $urlDePaciente  la ficha del paciente, o `null` si no se enlaza
     * @param  string  $claveDelListado  la clave elegida (`ListadosDisponibles`)
     */
    public function __construct(
        private Collection $pacientes,
        private FiltroDePacientesPorRango $filtro,
        private Collection $seguros = new Collection(),
        private string $urlBase = '/clinica/listados',
        private string $urlImprimir = '/clinica/listados/imprimir',
        private $urlDePaciente = null,
        private string $claveDelListado = ListadosDisponibles::PACIENTES,
    ) {
    }

    public function titulo(): string
    {
        return ListadosDisponibles::titulo($this->claveDelListado);
    }

    /** "12 pacientes · ingresados entre el 01/01/2000 y el 14/10/2026 · de 1 a 110 años · Femenino." */
    public function subtitulo(): string
    {
        $total = $this->total();

        $partes = [
            $total === 1 ? '1 paciente' : $total . ' pacientes',
            'ingresados entre el ' . $this->desdeFormateado() . ' y el ' . $this->hastaFormateado(),
            'de ' . $this->filtro->edadInicial() . ' a ' . $this->filtro->edadFinal() . ' años',
        ];

        foreach ($this->descripcionDeLosFiltrosOpcionales() as $filtro) {
            // Sexo y estado civil se leen solos («Femenino», «Casado(a)»); una empresa necesita su rótulo.
            $partes[] = $filtro['etiqueta'] === 'Empresa' ? 'empresa: ' . $filtro['valor'] : $filtro['valor'];
        }

        return implode(' · ', $partes) . '.';
    }

    public function total(): int
    {
        return $this->pacientes->count();
    }

    public function estaVacio(): bool
    {
        return $this->pacientes->isEmpty();
    }

    /** ¿Hay algo puesto que se pueda limpiar? Lo usa el enlace "Limpiar" del formulario. */
    public function hayFiltro(): bool
    {
        return $this->filtro->hayFiltro();
    }

    // ------------------------------------------------------------------------------------- El formulario

    public function desde(): string
    {
        return $this->filtro->rangoDeIngreso()->desde();
    }

    public function hasta(): string
    {
        return $this->filtro->rangoDeIngreso()->hasta();
    }

    public function edadInicial(): int
    {
        return $this->filtro->edadInicial();
    }

    public function edadFinal(): int
    {
        return $this->filtro->edadFinal();
    }

    /**
     * Las opciones del desplegable de sexo, con la elegida marcada. «Todos» no filtra.
     *
     * @return array<int,array{valor:string,texto:string,elegida:bool}>
     */
    public function opcionesDeSexo(): array
    {
        return $this->opciones(FormularioDePacienteVista::SEXOS, $this->filtro->sexo());
    }

    /** @return array<int,array{valor:string,texto:string,elegida:bool}> */
    public function opcionesDeEstadoCivil(): array
    {
        return $this->opciones(FormularioDePacienteVista::ESTADOS_CIVILES, $this->filtro->estadoCivil());
    }

    /**
     * Las opciones de procedencia: «Todas», «Personal» y cada empresa del catálogo del consultorio (el
     * `00` de PERSONAL ya es la segunda, así que no se repite).
     *
     * @return array<int,array{valor:string,texto:string,elegida:bool}>
     */
    public function opcionesDeProcedencia(): array
    {
        $elegida = $this->filtro->procedencia();
        $empresas = [FiltroDePacientesPorRango::PROCEDENCIA_PERSONAL => 'Personal'];

        foreach ($this->seguros as $seguro) {
            $codigo = CamposDePaciente::texto($seguro->codesegemp ?? null);
            $nombre = CamposDePaciente::texto($seguro->nombre ?? null);

            if ($codigo !== null && $codigo !== CatalogoDeSeguros::PERSONAL) {
                $empresas[$codigo] = $nombre ?? $codigo;
            }
        }

        // Una empresa pedida que ya no está en el catálogo se mantiene elegible: el filtro no la rechaza.
        if ($elegida !== null && ! array_key_exists($elegida, $empresas)) {
            $empresas[$elegida] = $elegida;
        }

        return $this->opciones($empresas, $elegida);
    }

    /**
     * Las filas de la tabla, ya formateadas.
     *
     * @return array<int,array<string,mixed>>
     */
    public function filas(): array
    {
        return $this->pacientes->map(function ($paciente) {
            $edad = FechaClinica::edad($paciente->fnacimiento);
            $cedula = CamposDePaciente::cedula($paciente->cedula);
            $nacionalidad = CamposDePaciente::texto($paciente->nac) ?? 'V';

            return [
                'historia'  => $this->historia($paciente->numhistoria ?? null),
                'nombre'    => CamposDePaciente::nombre($paciente->apellidos, $paciente->nombres),
                'url'       => $this->urlDePaciente !== null ? ($this->urlDePaciente)((int) $paciente->paciente_id) : null,
                'cedula'    => $cedula !== null ? $nacionalidad . '-' . $cedula : null,
                'edad'      => $edad !== null ? $edad . ($edad === 1 ? ' año' : ' años') : null,
                'ecivil'    => $this->etiqueta(FormularioDePacienteVista::ESTADOS_CIVILES, $paciente->codeestado),
                'sexo'      => $this->etiqueta(FormularioDePacienteVista::SEXOS, $paciente->sexo),
                'fingreso'  => FechaClinica::formato($paciente->fingreso),
                'empresa'   => CatalogoDeSeguros::paraMostrar($this->seguros, $paciente->codesegemp),
            ];
        })->all();
    }

    /**
     * El estado vacío, que distingue «no hay pacientes con esos filtros» de «este consultorio todavía no tiene
     * pacientes cargados».
     *
     * @return array{icono:string, titulo:string, texto:string}
     */
    public function vacio(): array
    {
        if ($this->hayFiltro()) {
            return [
                'icono'  => 'bi-search',
                'titulo' => 'Ningún paciente con esos filtros',
                'texto'  => 'Prueba con otro rango de ingreso, otra edad o sin los filtros de sexo, estado civil y procedencia. '
                    . 'Un paciente sin fecha de nacimiento no aparece mientras se filtre por edad.',
            ];
        }

        return [
            'icono'  => 'bi-people',
            'titulo' => 'Todavía no hay pacientes',
            'texto'  => 'Los pacientes aparecen acá cuando se registran en el consultorio.',
        ];
    }

    /** Lo que hay que avisar de los filtros: las fechas o las edades que venían al revés. `null` si nada. */
    public function aviso(): ?string
    {
        $avisos = [];

        if ($this->filtro->seVoltearonLasFechas()) {
            $avisos[] = 'Las fechas venían al revés: se buscó del ' . $this->desdeFormateado()
                . ' al ' . $this->hastaFormateado() . '.';
        }

        if ($this->filtro->seVoltearonLasEdades()) {
            $avisos[] = 'Las edades venían al revés: se buscó de ' . $this->filtro->edadInicial()
                . ' a ' . $this->filtro->edadFinal() . ' años.';
        }

        return $avisos === [] ? null : implode(' ', $avisos);
    }

    /**
     * Los filtros como pares etiqueta/valor, para la cabecera de la hoja impresa: un listado sin sus filtros
     * no dice nada en papel. El rango y la edad van siempre; sexo, estado civil y procedencia, si se pidieron.
     *
     * @return array<int,array{etiqueta:string,valor:string}>
     */
    public function descripcionDeLosFiltros(): array
    {
        return array_merge([
            ['etiqueta' => 'Ingreso desde', 'valor' => $this->desdeFormateado()],
            ['etiqueta' => 'Ingreso hasta', 'valor' => $this->hastaFormateado()],
            ['etiqueta' => 'Edad', 'valor' => $this->filtro->edadInicial() . ' a ' . $this->filtro->edadFinal() . ' años'],
        ], $this->descripcionDeLosFiltrosOpcionales());
    }

    /** De dónde sale la columna «Empresa», dicho en la pantalla. */
    public function notaDeEmpresa(): string
    {
        return 'La empresa es el nombre del seguro o de la empresa en el catálogo del consultorio; '
            . 'un código que no esté en él se muestra tal cual y «—» es un paciente que se atiende por su cuenta.';
    }

    public function urlBase(): string
    {
        return $this->urlBase;
    }

    /** La clave del listado elegido: el formulario la lleva en un campo oculto para no perderla al filtrar. */
    public function claveDelListado(): string
    {
        return $this->claveDelListado;
    }

    public function urlImprimir(): string
    {
        return $this->urlImprimir;
    }

    // ------------------------------------------------------------------------------------------ Adentro

    /** @return array<int,array{etiqueta:string,valor:string}> sexo, estado civil y procedencia, si se pidieron */
    private function descripcionDeLosFiltrosOpcionales(): array
    {
        $filtros = [];

        if ($this->filtro->sexo() !== null) {
            $filtros[] = ['etiqueta' => 'Sexo', 'valor' => $this->etiqueta(FormularioDePacienteVista::SEXOS, $this->filtro->sexo())];
        }

        if ($this->filtro->estadoCivil() !== null) {
            $filtros[] = ['etiqueta' => 'Estado civil', 'valor' => $this->etiqueta(FormularioDePacienteVista::ESTADOS_CIVILES, $this->filtro->estadoCivil())];
        }

        if ($this->filtro->procedencia() !== null) {
            $filtros[] = ['etiqueta' => 'Empresa', 'valor' => $this->procedenciaPedida()];
        }

        return $filtros;
    }

    private function procedenciaPedida(): string
    {
        $codigo = $this->filtro->codigoDeProcedencia();

        if ($this->filtro->procedencia() === FiltroDePacientesPorRango::PROCEDENCIA_PERSONAL) {
            return 'Personal';
        }

        return (string) (CatalogoDeSeguros::paraMostrar($this->seguros, $codigo) ?? $codigo);
    }

    /** El número de historia con sus ceros a la izquierda, o `null` si el paciente todavía no tiene. */
    private function historia($valor): ?string
    {
        $numero = CamposDePaciente::numeroDeHistoria($valor);

        if ($numero === null) {
            return null;
        }

        return ctype_digit($numero) ? str_pad($numero, self::DIGITOS_DE_LA_HISTORIA, '0', STR_PAD_LEFT) : $numero;
    }

    /** El texto de un código de catálogo, o el código tal cual si no está; `null` si no hay código. */
    private function etiqueta(array $catalogo, $codigo): ?string
    {
        $codigo = CamposDePaciente::texto($codigo);

        if ($codigo === null) {
            return null;
        }

        return $catalogo[strtoupper($codigo)] ?? $codigo;
    }

    /**
     * @param  array<string,string>  $catalogo
     * @return array<int,array{valor:string,texto:string,elegida:bool}>
     */
    private function opciones(array $catalogo, ?string $elegida): array
    {
        $opciones = [['valor' => '', 'texto' => 'Todos', 'elegida' => $elegida === null]];

        foreach ($catalogo as $valor => $texto) {
            $opciones[] = ['valor' => (string) $valor, 'texto' => $texto, 'elegida' => (string) $valor === $elegida];
        }

        return $opciones;
    }

    private function desdeFormateado(): string
    {
        return FechaClinica::formato($this->filtro->rangoDeIngreso()->desde());
    }

    private function hastaFormateado(): string
    {
        return FechaClinica::formato($this->filtro->rangoDeIngreso()->hasta());
    }
}
