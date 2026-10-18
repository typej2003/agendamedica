<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Agenda\EliminarCita;
use App\Clinica\Agenda\AgendamientoDeCitas;
use App\Clinica\Agenda\ArmadorDeAgenda;
use App\Clinica\Agenda\DiasNoLaborables;
use App\Clinica\Agenda\FormularioDeCita;
use App\Clinica\Agenda\PacientesDeCita;
use App\Clinica\Agenda\ParametrosDeAgenda;
use App\Clinica\Agenda\ResultadoDeAgendamiento;
use App\Clinica\Agenda\VueltaDeLaCita;
use App\Http\Controllers\Clinica\Concerns\ResuelveLaCitaDelContexto;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clinica\GuardarCitaRequest;
use App\Models\Cola;
use App\Models\Office;
use Carbon\Carbon;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Alta, edición y baja de una cita desde la web clínica. El controlador solo traduce HTTP: las reglas de
 * agendar (la cita pendiente, la jornada, el número y el monto) viven en `AgendamientoDeCitas`, el armado
 * del formulario en `FormularioDeCita` y lo que se pinta en `FormularioDeCitaVista`.
 */
class CitaController extends Controller
{
    use ResuelveLaCitaDelContexto;
    use ResuelveLosRegistrosDelMedico;

    public function __construct(
        private AgendamientoDeCitas $agendamiento,
        private FormularioDeCita $formulario,
        private PacientesDeCita $pacientes,
        private ArmadorDeAgenda $armador,
        private DiasNoLaborables $diasNoLaborables,
        private VueltaDeLaCita $vuelta,
    ) {
    }

    /**
     * El formulario de "Nueva cita".
     *
     * La sede y el día definen la **jornada** (modalidad, cupo y qué número le toca al próximo), así que se
     * eligen con un GET que vuelve a esta pantalla y el formulario se arma con eso ya resuelto en el
     * servidor. Es la misma decisión que la ventana `w_nueva_cita` del escritorio.
     */
    public function nueva(Request $request)
    {
        $contexto = $request->attributes->get('contexto_clinico');

        // Se puede llegar con el paciente ya elegido (desde su ficha); si no, se busca en el form.
        $paciente = $request->query('paciente_id') !== null
            ? $this->pacientes->delContexto($contexto, (int) $request->query('paciente_id'))
            : null;

        return view('clinica.agenda.formulario', ['vm' => $this->formulario->armar($contexto, [
            'cita'            => null,
            'accion'          => route('clinica.agenda.crear'),
            'fecha'           => ParametrosDeAgenda::fechaDe($request->query('fecha')),
            // La sede con la que abre: la pedida, la del contexto o —sin contexto y con una sola sede— esa
            // (O-03 y O-03b). El criterio vive en `ParametrosDeAgenda`, no acá.
            'sedeId'          => ParametrosDeAgenda::sedeDelFormulario($request, $contexto),
            'valores'         => ['paciente_id' => $paciente?->id],
            'pacienteElegido' => $paciente ? $this->pacientes->ficha($paciente, $this->pacientes->numHistoriaDe($contexto, $paciente)) : null,
            'pendiente'       => null,
            // "Hacer cita" desde los récipes de una consulta (WEB-5.2): a dónde vuelve al guardar.
            'volverA'         => $this->vueltaDe($request, $contexto, $request->query('volver_consulta')),
            'puedeAdministrarMotivos' => (bool) $request->user()?->trabajaLaConsulta(),
        ])]);
    }

    /**
     * Guardar la cita nueva. Si el paciente **ya tiene una cita pendiente** no se escribe nada todavía: se
     * vuelve a mostrar el formulario con la pregunta (mover esa cita, agendar otra o cancelar).
     */
    public function crear(GuardarCitaRequest $request)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        // Los `reg_medico` del médico van al agendamiento porque el alta rápida del paso 2 **numera la
        // historia** (O-04) con la misma regla que la ficha: el correlativo se lee de todos sus registros.
        $resultado = $this->agendamiento->agendar(
            $contexto,
            $request->validated(),
            $this->registrosDelMedico($contexto['medico']),
        );
        $volverA = $this->vueltaDe($request, $contexto, $request->validated()['volver_consulta'] ?? null);

        if ($resultado->esPregunta()) {
            return view('clinica.agenda.formulario', ['vm' => $this->formulario->armar($contexto, [
                'cita'            => null,
                'accion'          => route('clinica.agenda.crear'),
                'fecha'           => $resultado->fecha,
                'sedeId'          => $resultado->sede?->id,
                'valores'         => $resultado->valores,
                'pacienteElegido' => $this->pacientes->ficha($resultado->objetivo['paciente'], $resultado->objetivo['numhistoria'], $resultado->valores),
                'pendiente'       => $resultado->pendiente,
                'volverA'         => $volverA,
                'puedeAdministrarMotivos' => (bool) $request->user()?->trabajaLaConsulta(),
            ])]);
        }

        // Si se llegó desde los récipes de una consulta (WEB-5.2), se vuelve ahí; si no, a la agenda.
        if ($volverA !== null) {
            return redirect($volverA['url'])->with('estado', $resultado->mensaje());
        }

        return $this->volverALaAgenda($contexto, $resultado);
    }

    /** El formulario de edición, con la cita puesta (misma vista que "Nueva cita"). */
    public function editar(Request $request, Cola $cola)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $cita = $this->citaDelContexto($request, $cola);

        if ($cita->movida_escritorio) {
            return $this->volverConError($cita, $this->avisoDeCitaMovida());
        }

        if ((int) $cita->atendido === 1) {
            return $this->volverConError($cita, 'Una cita ya atendida no se reagenda. Si hay que corregir el monto, usa Cobrar.');
        }

        $sedes = $this->armador->sedes($contexto['reg_medico'], $contexto['medico']->id);
        $sede = $sedes->first(fn (Office $opcion) => $opcion->medical_center_id === ($cita->medical_center_id !== null ? (int) $cita->medical_center_id : null));
        $paciente = $this->pacientes->deLaCita($cita);

        return view('clinica.agenda.formulario', ['vm' => $this->formulario->armar($contexto, [
            'cita'    => $cita,
            'accion'  => route('clinica.agenda.actualizar', $cita->id),
            'fecha'   => Carbon::parse($cita->fecha),
            'sedeId'  => $sede?->id,
            'valores' => [
                'sede'        => $sede?->id,
                'fecha'       => Carbon::parse($cita->fecha)->toDateString(),
                'hora'        => $cita->hora_ini !== null ? substr($cita->hora_ini, 0, 5) : null,
                // La razón vive en `tipo` (escritorio) o en `motivo` (móvil): se preselecciona el que esté.
                // Si no está en el catálogo, la vista agrega la opción para no perderlo.
                'motivo'      => $cita->tipo ?: $cita->motivo,
                'monto'       => $cita->monto,
                'paciente_id' => $paciente?->id,
            ],
            'pacienteElegido' => $paciente !== null
                ? $this->pacientes->ficha($paciente, $cita->numhistoria !== null ? (int) $cita->numhistoria : null)
                : null,
            'pendiente' => null,
            'puedeAdministrarMotivos' => (bool) $request->user()?->trabajaLaConsulta(),
        ])]);
    }

    /** Guardar la edición (reagendar es editar la misma fila, wiki `02-modulo-agenda.md` §4). */
    public function actualizar(GuardarCitaRequest $request, Cola $cola)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $cita = $this->citaDelContexto($request, $cola);

        if ($cita->movida_escritorio) {
            return back()->with('error', $this->avisoDeCitaMovida());
        }

        if ((int) $cita->atendido === 1) {
            return back()->with('error', 'Una cita ya atendida no se reagenda. Si hay que corregir el monto, usa Cobrar.');
        }

        return $this->volverALaAgenda($contexto, $this->agendamiento->reagendar($contexto, $cita, $request->validated()));
    }

    /** Eliminar: lo decide `EliminarCita` (una cita atendida o con pago no se borra). */
    public function eliminar(Request $request, Cola $cola, EliminarCita $eliminar)
    {
        $cita = $this->citaDelContexto($request, $cola);

        try {
            $eliminar->ejecutar($cita);
        } catch (InvalidArgumentException $excepcion) {
            return back()->with('error', $excepcion->getMessage());
        }

        return back()->with('estado', 'Cita eliminada.');
    }

    /**
     * Volver a la agenda en el día y la sede de la cita, que es donde el usuario espera verla. El aviso del
     * día no laborable no bloquea: el escritorio aborta y la web **avisa y deja decidir** (decisión del
     * 2026-10-09, WEB-2.8), porque la excepción real —"es feriado pero el Dr. atiende igual"— no puede
     * quedar sin camino. Viaja en `avisos`, una lista, para no pisarse con el mensaje del resultado.
     */
    private function volverALaAgenda(array $contexto, ResultadoDeAgendamiento $resultado)
    {
        $destino = redirect()->route('clinica.agenda', [
            'vista' => 'dia',
            'fecha' => $resultado->fecha->toDateString(),
            'sede'  => $resultado->sede?->id ?? 'todas',
        ]);

        $aviso = $this->diasNoLaborables->avisoDelDia($contexto, $resultado->fecha);
        if ($aviso !== null) {
            $destino = $destino->with('avisos', [$aviso]);
        }

        return $destino->with('estado', $resultado->mensaje());
    }

    /** A dónde vuelve el formulario si se llegó desde los récipes de una consulta (`VueltaDeLaCita`), o `null`. */
    private function vueltaDe(Request $request, array $contexto, $consultaId): ?array
    {
        return $this->vuelta->de(
            $contexto,
            $this->registrosDelMedico($contexto['medico']),
            (bool) $request->user()?->trabajaLaConsulta(),
            $consultaId,
        );
    }

    /** De vuelta a la agenda del día de la cita, con un error. */
    private function volverConError(Cola $cita, string $mensaje)
    {
        return redirect()
            ->route('clinica.agenda', ['vista' => 'dia', 'fecha' => Carbon::parse($cita->fecha)->toDateString(), 'sede' => 'todas'])
            ->with('error', $mensaje);
    }
}
