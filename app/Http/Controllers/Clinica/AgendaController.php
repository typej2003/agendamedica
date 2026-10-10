<?php

namespace App\Http\Controllers\Clinica;

use App\Clinica\Agenda\LectorDeAgenda;
use App\Clinica\Agenda\ParametrosDeAgenda;
use App\Clinica\Agenda\Vistas\AgendaVista;
use App\Clinica\Agenda\Vistas\ListadoDelDiaVista;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * La **lectura** de la agenda de la web clínica (PLAN-WEB.md, F2): la vista día / semana / mes por sede,
 * con la modalidad y el cupo de cada jornada, y el listado del día que se imprime.
 *
 * Lo demás vive en su propio controlador: las citas (alta, edición y baja) en `CitaController`, las
 * acciones del mostrador en `MostradorController` y los recordatorios en `RecordatorioController`.
 * Nada de la lectura se recalcula en la vista: la resuelve `LectorDeAgenda` y la formatea `AgendaVista`.
 */
class AgendaController extends Controller
{
    public function index(Request $request, LectorDeAgenda $lector)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $lectura = $lector->leer(ParametrosDeAgenda::desde($request, $contexto), $contexto);

        return view('clinica.agenda.index', [
            'vm' => new AgendaVista($lectura, $request->query()),
        ]);
    }

    /**
     * El listado del día para imprimir (WEB-2.7): reemplaza al `dw_1.print()` del botón *Imprimir* de
     * `w_hacer_cita`. Imprime el navegador (PLAN-WEB.md, R6), no el servidor.
     */
    public function imprimir(Request $request, LectorDeAgenda $lector)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $fecha = ParametrosDeAgenda::fechaDe($request->query('fecha'));
        $sedeId = ParametrosDeAgenda::sedeDe($request, $contexto);
        $sedes = $lector->sedes($contexto);

        return view('clinica.agenda.imprimir', [
            'vm' => new ListadoDelDiaVista(
                $fecha,
                $sedeId ? $sedes->firstWhere('id', $sedeId) : null,
                $contexto['medico']->nombreMostrar,
                $lector->citasDelDia($contexto, $sedes, $fecha, $sedeId),
            ),
        ]);
    }
}
