<?php

namespace App\Http\Controllers\Clinica;

use App\Clinica\Agenda\LectorDeAgenda;
use App\Clinica\Agenda\MedicosDeLaCita;
use App\Clinica\Agenda\ParametrosDeAgenda;
use App\Clinica\Agenda\Vistas\AgendaVista;
use App\Clinica\Agenda\Vistas\ListadoDelDiaVista;
use App\Clinica\Impresion\DocumentosPorCita;
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
    public function __construct(private MedicosDeLaCita $medicosDeLaCita)
    {
    }

    public function index(Request $request, LectorDeAgenda $lector, DocumentosPorCita $documentosPorCita)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $lectura = $lector->leer(ParametrosDeAgenda::desde($request, $contexto), $contexto);

        return view('clinica.agenda.index', [
            'vm' => new AgendaVista(
                $lectura,
                $request->query(),
                // "Atender abre la consulta" (WEB-4.1): la fila ofrece el botón solo a quien puede entrar
                // a la consulta —el médico—, con la misma regla que protege la ruta del shell (§3.14).
                (bool) $request->user()?->trabajaLaConsulta(),
                // "Imprimir documentos" (WEB-5.2/5.3a): qué citas atendidas tienen algo que imprimir, en lote para toda la agenda.
                $documentosPorCita->conDocumentos($contexto['reg_medico'], $lectura->jornadas->flatMap(fn ($jornada) => $jornada->citas)),
                // El médico de cada cita (Fase 3 del cambio de `reg_medico`): con más de un doctor en el
                // consultorio la fila lo muestra y deja asignarlo. Se resuelve una vez para toda la página.
                $this->medicosDeLaCita->nombres($contexto['reg_medico']),
            ),
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
