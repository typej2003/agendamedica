<?php

namespace App\Http\Controllers\Clinica;

use App\Clinica\FechaDelConsultorio;
use App\Clinica\Listados\EmbarazosEntreFechas;
use App\Clinica\Listados\FiltroDeEmbarazos;
use App\Clinica\Listados\FiltroDePacientesPorRango;
use App\Clinica\Listados\ListadosDisponibles;
use App\Clinica\Listados\PacientesPorRango;
use App\Clinica\Listados\Vistas\ListadoDeEmbarazosVista;
use App\Clinica\Listados\Vistas\ListadoDePacientesVista;
use App\Clinica\Pacientes\CatalogoDeSeguros;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * **Los listados clínicos entre fechas** (WEB-6.8): la pantalla `/clinica/listados`, que hoy muestra el
 * primero de ellos —el **listado de embarazos / partos**, el *Listado de Partos* del escritorio
 * (`w_reportes_consultas.srw:449` → `w_reporte_consulta_partos.srw`)— y está armada para que los otros dos
 * de la tarea entren con el mismo patrón.
 *
 * Es **una pantalla parametrizada** y no una pantalla por reporte: una ruta, un formulario de filtros
 * (rango + edades + modo por semanas) y una grilla. `ListadosDisponibles` es el catálogo de las claves con
 * pantalla y `?listado=` viaja en la dirección; hoy hay una sola, así que el controlador arma su ViewModel
 * (el de embarazos). Un listado nuevo suma su lectura en `App\Clinica\Listados`, su ViewModel en `Vistas/`
 * y su clave en el catálogo, sin ruta ni pantalla nuevas.
 *
 * **Sólo el médico**: son reportes de su consulta (la secretaría recibe **403** por el grupo de rutas, que
 * es `role:Root|Medico`). El legado los tiene en el escritorio del médico y la web respeta esa partición,
 * la misma de la consulta y los catálogos clínicos.
 *
 * Controlador delgado: lee el contexto, normaliza los filtros —la semántica está en `FiltroDeEmbarazos` y
 * su cuenta en `RangoDeFechas`—, llama a una lectura, arma el ViewModel y devuelve la vista. El mismo
 * ViewModel sirve para la pantalla y para la hoja impresa, que es el precedente de la historia del paciente
 * (`HistoriaDelPacienteController::imprimir`): una página sin armazón, con su propio CSS y `@page`, que
 * imprime el navegador.
 */
class ListadosController extends Controller
{
    public function __construct(
        private EmbarazosEntreFechas $embarazos,
        private PacientesPorRango $pacientes,
        private CatalogoDeSeguros $seguros,
    ) {
    }

    public function index(Request $request)
    {
        $contexto = $request->attributes->get('contexto_clinico');

        return view('clinica.listados.index', [
            'vm' => $this->vista($request, $contexto),
        ]);
    }

    /**
     * La hoja que va a la impresora, con **los mismos filtros** que la pantalla (llegan por la dirección,
     * como en la vista previa de la agenda). El legado imprime su propio DataWindow
     * (`w_panel_impresion` sobre `dw_1`); acá la imprime el navegador, con el patrón de la historia.
     */
    public function imprimir(Request $request)
    {
        $contexto = $request->attributes->get('contexto_clinico');

        return view('clinica.listados.imprimir', [
            'vm' => $this->vista($request, $contexto),
            // "Hoy" y el médico salen del contexto del consultorio, no de la vista (misma regla que el
            // resumen de historia clínica de WEB-3.5).
            'medico' => $contexto['medico']->nombreMostrar,
            'fecha'  => FechaDelConsultorio::hoy()->format('d/m/Y'),
        ]);
    }

    /**
     * El ViewModel del listado elegido, con lo ya leído.
     *
     * @param  array{medico:object, reg_medico:string, office:object, specialty:object}  $contexto
     */
    private function vista(Request $request, array $contexto): ListadoDeEmbarazosVista|ListadoDePacientesVista
    {
        // La clave del listado viaja en la dirección; una que no exista vuelve al de por defecto en vez de
        // mostrar una pantalla a medias. Cada listado arma su filtro, su lectura y su ViewModel.
        $clave = ListadosDisponibles::normalizar($request->query('listado'));

        if ($clave === ListadosDisponibles::PACIENTES) {
            return $this->pacientesPorRango($request, $contexto, $clave);
        }

        $filtro = FiltroDeEmbarazos::de($request->query(), FechaDelConsultorio::hoy());

        return new ListadoDeEmbarazosVista(
            $this->embarazos->filas($contexto['reg_medico'], $filtro),
            $filtro,
            // El catálogo con el que se resuelve la "Procedencia", ya leído: la misma lectura que usa la
            // ficha del paciente. Si todavía no está el nombre, el ViewModel muestra el código.
            $this->seguros->de($contexto['reg_medico']),
            route('clinica.listados'),
            route('clinica.listados.imprimir', $request->query()),
            // Las URLs las resuelve el controlador: el ViewModel se prueba sin contenedor.
            fn (int $paciente) => route('clinica.pacientes.ver', $paciente),
            $clave,
        );
    }

    /**
     * El listado de pacientes por rango (*Indicadores pacientes* del escritorio): los pacientes del médico
     * ingresados entre dos fechas, con los filtros de edad, sexo, estado civil y procedencia.
     *
     * @param  array{medico:object, reg_medico:string, office:object, specialty:object}  $contexto
     */
    private function pacientesPorRango(Request $request, array $contexto, string $clave): ListadoDePacientesVista
    {
        $filtro = FiltroDePacientesPorRango::de($request->query(), FechaDelConsultorio::hoy());

        return new ListadoDePacientesVista(
            $this->pacientes->filas($contexto['reg_medico'], $filtro),
            $filtro,
            $this->seguros->de($contexto['reg_medico']),
            route('clinica.listados', ['listado' => $clave]),
            route('clinica.listados.imprimir', $request->query()),
            fn (int $paciente) => route('clinica.pacientes.ver', $paciente),
            $clave,
        );
    }
}
