<?php

namespace App\Clinica\Listados\Vistas;

use App\Clinica\Embarazo\EdadGestacional;
use App\Clinica\Listados\FiltroDeEmbarazos;
use App\Clinica\Listados\ListadosDisponibles;
use App\Clinica\Pacientes\CamposDePaciente;
use App\Support\FechaClinica;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * **Lo que muestra el listado de embarazos / partos** (WEB-6.8): las seis columnas del *Listado de Partos*
 * del escritorio, ya formateadas, con los filtros que se están mirando y los cuatro estados de la pantalla.
 *
 * Las columnas son las del legado y en su orden —`d_partos.srd`: Pacientes, F. ingreso, Edad, F.U.R., F.P.P.
 * y Procedencia—, con las cuentas también del legado:
 *
 *  - **F.P.P.** es la FUR **+ 283** (`relativedate(fur, 283)` del DataWindow): la cuenta la hace
 *    `EdadGestacional::fpp()`, que es la clase que documenta los tres días de FPP del legado (281 el control
 *    prenatal, 283 los reportes y los ecos). No se escribe acá.
 *  - **Edad** es la de `FechaClinica::edad()`, la misma que el listado de pacientes: el escritorio divide los
 *    días vividos por 365, que es la misma idea a menos del puñado de días de diferencia que el filtro
 *    convierte a fechas de nacimiento (ver `FiltroDeEmbarazos`). El filtro y la columna tienen que decir lo
 *    mismo.
 *  - **Procedencia** es el nombre del seguro/empresa del catálogo del consultorio (`seg_emp`, que es la tabla
 *    del desplegable `d_dww_empresas` del escritorio); **si el código no está en el catálogo se muestra el
 *    código tal cual**, igual que en la ficha del paciente. El catálogo de seguros/empresas todavía no tiene
 *    pantalla de administración (es lo que falta de F8), pero este reporte **lee** el que ya existe en la
 *    base: no se inventa ningún nombre. El código `00` (**PERSONAL** en el legado) se muestra como "—", la
 *    misma regla de la ficha.
 *
 * **Nombres:** el legado pega `nombres + ' ' + apellidos`; acá se usa `CamposDePaciente::nombre()`, que es
 * como la web muestra a un paciente en todas sus pantallas (`Apellidos, Nombres`). Es una decisión de la
 * pantalla y no del dato.
 *
 * **Sin totales ni cortes**: los dos DataWindows del legado no tienen ningún `sum` ni `count` —verificado en
 * la fuente—, así que tampoco hay acá. Lo único que se cuenta es cuántas gestas se están mirando, para el
 * subtítulo.
 *
 * La clase no toca la base: los seguros llegan leídos y las URLs las resuelve el controlador (`route()` no se
 * llama acá, así que se prueba sin contenedor).
 */
final class ListadoDeEmbarazosVista
{
    /**
     * @param  Collection<int,object>|LengthAwarePaginator<int,object>  $gestas  las filas de
     *         `EmbarazosEntreFechas::filas()` (paginadas) o `::todas()` (la hoja impresa)
     * @param  Collection<int,object>  $seguros  el catálogo del consultorio (`codesegemp`, `nombre`)
     * @param  string  $urlBase  el listado sin filtros (`route('clinica.listados')`)
     * @param  string  $urlImprimir  la hoja impresa con **los mismos filtros** que la pantalla
     * @param  callable(int):string|null  $urlDePaciente  la ficha del paciente, o `null` si no se enlaza
     * @param  string  $claveDelListado  la clave elegida (`ListadosDisponibles`), que da el título y viaja en
     *         el formulario para no perderse al filtrar
     */
    public function __construct(
        private Collection|LengthAwarePaginator $gestas,
        private FiltroDeEmbarazos $filtro,
        private Collection $seguros = new Collection(),
        private string $urlBase = '/clinica/listados',
        private string $urlImprimir = '/clinica/listados/imprimir',
        private $urlDePaciente = null,
        private string $claveDelListado = ListadosDisponibles::EMBARAZOS,
    ) {
    }

    /**
     * El título de la pantalla: el de la clave elegida (`ListadosDisponibles`), salvo en el modo "Por Semanas",
     * donde es el del DataWindow del legado (`d_partos_semana.srd`, `compute_3`: «Embarazos de N semanas de
     * gestación»).
     */
    public function titulo(): string
    {
        if ($this->filtro->porSemanas() && $this->filtro->semana() !== null) {
            $semana = $this->filtro->semana();

            return 'Embarazos de ' . $semana . ' ' . ($semana === 1 ? 'semana' : 'semanas') . ' de gestación';
        }

        return ListadosDisponibles::titulo($this->claveDelListado);
    }

    /** "12 gestas · FPP entre el 01/06/2026 y el 30/06/2026 · pacientes de 1 a 110 años." */
    public function subtitulo(): string
    {
        if ($this->filtro->semanaInvalida()) {
            return 'Falta la semana de gestación.';
        }

        $total = $this->total();

        $partes = [$total === 1 ? '1 gesta' : $total . ' gestas'];

        if ($this->filtro->porSemanas()) {
            $partes[] = 'semana ' . $this->filtro->semana() . ' de gestación';
        } else {
            $partes[] = 'FPP entre el ' . $this->desdeFormateado() . ' y el ' . $this->hastaFormateado();
        }

        $partes[] = 'pacientes de ' . $this->filtro->edadInicial() . ' a ' . $this->filtro->edadFinal() . ' años';

        return implode(' · ', $partes) . '.';
    }

    public function total(): int
    {
        return $this->gestas instanceof LengthAwarePaginator
            ? $this->gestas->total()
            : $this->gestas->count();
    }

    public function estaVacio(): bool
    {
        return $this->total() === 0;
    }

    /**
     * El paginador de la pantalla, o `null` cuando no hay nada que paginar o la hoja es la impresa (que trae
     * todas las filas, porque el papel no tiene páginas).
     */
    public function paginador(): ?LengthAwarePaginator
    {
        return $this->gestas instanceof LengthAwarePaginator && $this->gestas->hasPages()
            ? $this->gestas
            : null;
    }

    /** ¿Hay algo puesto que se pueda limpiar? Lo usa el enlace "Limpiar" del formulario. */
    public function hayFiltro(): bool
    {
        return $this->filtro->hayFiltro();
    }

    // ------------------------------------------------------------------------------------- El formulario
    //
    // Los valores para volver a escribir los filtros en el formulario. El rango va como `AAAA-MM-DD`, que es
    // lo que come un `<input type="date">`; la semana, como texto, para no perder lo que se tecleó aunque no
    // sea un número de 1 a 40.

    public function desde(): string
    {
        return $this->filtro->rango()->desde();
    }

    public function hasta(): string
    {
        return $this->filtro->rango()->hasta();
    }

    public function edadInicial(): int
    {
        return $this->filtro->edadInicial();
    }

    public function edadFinal(): int
    {
        return $this->filtro->edadFinal();
    }

    public function porSemanas(): bool
    {
        return $this->filtro->porSemanas();
    }

    public function semanaPedida(): string
    {
        return $this->filtro->semanaPedida();
    }

    /** La semana pedida no es un número de 1 a 40: la pantalla lo dice y no ofrece "Ver todos". */
    public function semanaInvalida(): bool
    {
        return $this->filtro->semanaInvalida();
    }

    /**
     * Las filas de la tabla, ya formateadas.
     *
     * @return array<int,array<string,mixed>>
     */
    public function filas(): array
    {
        $procedencias = $this->procedencias();

        $gestas = $this->gestas instanceof LengthAwarePaginator
            ? $this->gestas->getCollection()
            : $this->gestas;

        return $gestas->map(function ($gesta) use ($procedencias) {
            $edad = FechaClinica::edad($gesta->fnacimiento);
            $historia = CamposDePaciente::numeroDeHistoria($gesta->numhistoria ?? null);
            $codigo = CamposDePaciente::texto($gesta->codesegemp);
            // `00` es PERSONAL en el catálogo del legado: es el paciente que se atiende por su cuenta y no se
            // muestra como si tuviera una empresa (la misma regla de la ficha, `FichaDePacienteVista::seguro`).
            $procedencia = ($codigo === null || $codigo === '00') ? null : ($procedencias[$codigo] ?? $codigo);

            return [
                'nombre'      => CamposDePaciente::nombre($gesta->apellidos, $gesta->nombres),
                'historia'    => $historia !== null ? 'Historia ' . $historia : 'Sin historia',
                'url'         => $this->urlDePaciente !== null ? ($this->urlDePaciente)((int) $gesta->paciente_id) : null,
                'fingreso'    => FechaClinica::formato($gesta->fingreso),
                'edad'        => $edad !== null ? $edad . ($edad === 1 ? ' año' : ' años') : null,
                'fur'         => FechaClinica::formato($gesta->fur),
                'fpp'         => FechaClinica::formato(EdadGestacional::fpp($gesta->fur, FiltroDeEmbarazos::DIAS_DE_LA_FPP)),
                'procedencia' => $procedencia,
            ];
        })->all();
    }

    /**
     * El estado vacío, que distingue la semana que falta de "no hay embarazos en lo que pediste" y de "este
     * consultorio todavía no tiene embarazos cargados".
     *
     * @return array{icono:string, titulo:string, texto:string}
     */
    public function vacio(): array
    {
        if ($this->filtro->semanaInvalida()) {
            return [
                'icono'  => 'bi-exclamation-triangle',
                'titulo' => 'Falta la semana de gestación',
                'texto'  => 'Escribe un número de 1 a 40 para listar los embarazos de esa semana.',
            ];
        }

        if ($this->hayFiltro()) {
            return [
                'icono'  => 'bi-search',
                'titulo' => 'Ningún embarazo con esos filtros',
                'texto'  => 'Prueba con otro rango de fechas, otra edad o sin el filtro por semanas.',
            ];
        }

        return [
            'icono'  => 'bi-clipboard2-pulse',
            'titulo' => 'Todavía no hay embarazos cargados',
            'texto'  => 'Las gestas aparecen acá cuando se carga el control prenatal de una paciente.',
        ];
    }

    /**
     * Lo que hay que avisar de los filtros: las fechas o las edades que venían al revés (se dieron vuelta y
     * se buscó igual) y la semana que no se entiende. `null` cuando no hay nada que decir.
     */
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

        if ($this->filtro->semanaInvalida()) {
            $avisos[] = 'Escribe una semana de gestación entre 1 y 40 para usar el listado por semanas.';
        }

        return $avisos === [] ? null : implode(' ', $avisos);
    }

    /**
     * Los filtros como pares etiqueta/valor, para la cabecera de la hoja impresa: un listado sin su rango no
     * dice nada cuando se lee en papel.
     *
     * @return array<int,array{etiqueta:string,valor:string}>
     */
    public function descripcionDeLosFiltros(): array
    {
        $filtros = $this->filtro->porSemanas()
            ? [['etiqueta' => 'Semana de gestación', 'valor' => (string) $this->filtro->semanaPedida()]]
            : [
                ['etiqueta' => 'Desde', 'valor' => $this->desdeFormateado()],
                ['etiqueta' => 'Hasta', 'valor' => $this->hastaFormateado()],
            ];

        $filtros[] = [
            'etiqueta' => 'Edad',
            'valor'    => $this->filtro->edadInicial() . ' a ' . $this->filtro->edadFinal() . ' años',
        ];

        return $filtros;
    }

    /**
     * De dónde sale la columna "Procedencia", dicho en la pantalla: es el nombre del seguro o de la empresa
     * del catálogo del consultorio y, si el código no está en él, el código. Se anota porque el catálogo
     * todavía no tiene pantalla de administración (lo que falta de F8).
     */
    public function notaDeProcedencia(): string
    {
        return 'La procedencia es el nombre del seguro o de la empresa en el catálogo del consultorio; '
            . 'un código que no esté en él se muestra tal cual.';
    }

    /** El listado sin filtros: a dónde vuelve "Limpiar". */
    public function urlBase(): string
    {
        return $this->urlBase;
    }

    /**
     * La clave del listado elegido (`ListadosDisponibles`). El formulario la lleva en un campo oculto: un
     * `GET` reemplaza la barra de la dirección con sus propios campos, así que sin esto elegir un listado y
     * después filtrar volvería al de por defecto.
     */
    public function claveDelListado(): string
    {
        return $this->claveDelListado;
    }

    /** La hoja impresa con los mismos filtros que se están mirando. */
    public function urlImprimir(): string
    {
        return $this->urlImprimir;
    }

    /** El extremo del rango ya legible (`dd/mm/aaaa`), como el resto de las fechas de la web. */
    private function desdeFormateado(): string
    {
        return FechaClinica::formato($this->filtro->rango()->desde());
    }

    private function hastaFormateado(): string
    {
        return FechaClinica::formato($this->filtro->rango()->hasta());
    }

    /**
     * El nombre de cada código de seguro/empresa, indexado por código.
     *
     * El catálogo llega ya leído del consultorio (`CatalogoDeSeguros::de()`, que es lo que también usa la
     * ficha) y acá sólo se busca el nombre; un código que no esté queda con su propio texto.
     *
     * @return array<string,string>
     */
    private function procedencias(): array
    {
        $mapa = [];

        foreach ($this->seguros as $seguro) {
            $codigo = CamposDePaciente::texto($seguro->codesegemp ?? null);
            $nombre = CamposDePaciente::texto($seguro->nombre ?? null);

            if ($codigo !== null && $nombre !== null) {
                $mapa[$codigo] = $nombre;
            }
        }

        return $mapa;
    }
}
