<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Agenda\AtenderCita;
use App\Actions\Agenda\CobrarCita;
use App\Actions\Agenda\ConfirmarCita;
use App\Actions\Agenda\ReordenarCola;
use App\Actions\Pacientes\AsignarHistoria;
use App\Http\Controllers\Clinica\Concerns\ResuelveLaCitaDelContexto;
use App\Http\Controllers\Clinica\Concerns\ResuelveLosRegistrosDelMedico;
use App\Http\Controllers\Controller;
use App\Models\Cola;
use App\Models\MedicoPaciente;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Las acciones del mostrador sobre una cita —confirmar, atender, cobrar, reordenar la cola y pasar un
 * paciente sin historia a cita formal—, todas sobre las **Actions compartidas** con el sync del móvil
 * (PLAN-WEB.md, R3). El controlador solo valida que la cita sea del contexto y traduce el resultado a un
 * mensaje.
 */
class MostradorController extends Controller
{
    use ResuelveLaCitaDelContexto;
    use ResuelveLosRegistrosDelMedico;

    /** Confirmar la cita (o quitarle la confirmación, mientras no esté atendida). */
    public function confirmar(Request $request, Cola $cola, ConfirmarCita $accion)
    {
        $cita = $this->citaDelContexto($request, $cola);

        if ($cita->movida_escritorio) {
            return back()->with('error', $this->avisoDeCitaMovida());
        }

        $estado = (int) $request->input('estado', Cola::ESTADO_CONFIRMADA);

        if ($estado === Cola::ESTADO_NO_CONFIRMADA && (int) $cita->atendido === 1) {
            return back()->with('error', 'Una cita ya atendida no se puede quedar sin confirmar.');
        }

        $accion->ejecutar($cita, $estado, null, 'web');

        return back()->with(
            'estado',
            $estado === Cola::ESTADO_NO_CONFIRMADA ? 'Se quitó la confirmación.' : 'Cita confirmada.'
        );
    }

    /**
     * Marcar la cita como atendida. **Atender implica confirmado** (lo resuelve la Action).
     *
     * Abrir la consulta es otra cosa: llega con el módulo Consulta (F4), que es donde existe la pantalla.
     * Hasta entonces "Atender" deja la marca, que es el dato que la consulta va a usar.
     */
    public function atender(Request $request, Cola $cola, AtenderCita $accion)
    {
        $cita = $this->citaDelContexto($request, $cola);

        if ($cita->movida_escritorio) {
            return back()->with('error', $this->avisoDeCitaMovida());
        }

        $accion->ejecutar($cita, true, null, 'web');

        return back()->with('estado', 'Cita marcada como atendida.');
    }

    /** Cobrar: abono parcial, sumado a lo ya pagado (ver `CobrarCita`). */
    public function cobrar(Request $request, Cola $cola, CobrarCita $accion)
    {
        $cita = $this->citaDelContexto($request, $cola);

        $datos = $request->validate([
            'recibido' => ['required', 'numeric', 'min:0'],
            'monto'    => ['nullable', 'numeric', 'min:0'],
        ]);

        $accion->recibir(
            $cita,
            (float) $datos['recibido'],
            isset($datos['monto']) ? (float) $datos['monto'] : null,
            null,
            'web'
        );

        return back()->with('estado', 'Cobro registrado.');
    }

    /**
     * **"Pasar a cita formal"** (WEB-2.11): asignarle la historia al paciente de una cita que todavía no
     * tiene número, con lo que la cita deja de ser "sin historia" —es la misma fila, enlazada por
     * `cola.numhistoria`— y el paciente queda listo para atender.
     *
     * Es la acción del `pb_3` de `w_hacer_cita` (`:801-805`) → `w_pasar_pacientes_n_reg_consulta_cita`,
     * pero sobre el modelo de AppDDR: el escritorio crea un paciente nuevo en `pacientes` y otra cita;
     * acá el paciente y la cita **ya existen** (los creó el alta de la web, WEB-2.2, anclados por
     * `paciente_sinhistoria_id`) y lo único que falta es el número. La regla vive en la Action
     * compartida con el sync del móvil: `AsignarHistoria`.
     *
     * El número **no se puede adelantar** en el formulario —lo calcula el servidor al asignarlo con el
     * correlativo vigente del médico—, así que la pantalla confirma el efecto antes y acá se informa el
     * número después. Idempotente: si el paciente ya la tenía, la Action devuelve la suya y no numera
     * dos veces (el doble clic o el reintento terminan en el mismo lugar).
     *
     * Vuelve **explícitamente** a la agenda del día y la sede de la cita (no con `back()`): el destino
     * es el mismo con o sin el `Referer` que manda el navegador, que es la única forma de que el
     * resultado no dependa de cómo llegó la petición.
     */
    public function pasarACitaFormal(Request $request, Cola $cola, AsignarHistoria $accion)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $cita = $this->citaDelContexto($request, $cola);
        $volver = $this->urlDeLaAgendaDe($request, $cita);

        $pacienteId = $this->pacienteDeLaCita($cita);

        if ($pacienteId === null) {
            return $volver->with('error', 'Esta cita no tiene un paciente identificable al que asignarle la historia.');
        }

        $teniaHistoria = $cita->numhistoria !== null;

        try {
            $historia = $accion->ejecutar($contexto['medico'], $this->registrosDelMedico($contexto['medico']), $pacienteId);
        } catch (InvalidArgumentException $excepcion) {
            return $volver->with('error', $excepcion->getMessage());
        }

        return $volver->with('estado', $teniaHistoria
            ? 'Este paciente ya tenía historia: H. ' . $historia->numhistoria . '.'
            : 'Cita formal: al paciente se le asignó la historia H. ' . $historia->numhistoria . ' y su cita quedó enlazada.');
    }

    /**
     * La agenda del día y la sede de la cita: donde el usuario ve el resultado de la acción. La sede
     * sale de la cita (no del `Referer`), así se cae al panel de la cita que se acaba de formalizar.
     */
    private function urlDeLaAgendaDe(Request $request, Cola $cita): RedirectResponse
    {
        $contexto = $request->attributes->get('contexto_clinico');

        return redirect()->route('clinica.agenda', [
            'vista' => 'dia',
            'fecha' => Carbon::parse($cita->fecha)->toDateString(),
            'sede'  => $cita->medical_center_id !== null
                ? ($contexto['sedes']->firstWhere('medical_center_id', (int) $cita->medical_center_id)?->id ?? 'todas')
                : 'todas',
        ]);
    }

    /**
     * `pacientes.id` del paciente de la cita: el vínculo normal es la historia
     * (`medico_pacientes.numhistoria`) y, mientras no la tenga, el ancla
     * `cola.paciente_sinhistoria_id`. Una cita sin ninguna de las dos no dice a quién numerar (en los
     * datos del legado las hay: filas de `cola` con `numhistoria` nulo).
     */
    private function pacienteDeLaCita(Cola $cita): ?int
    {
        if ($cita->numhistoria !== null) {
            return MedicoPaciente::where('reg_medico', $cita->reg_medico)
                ->where('numhistoria', (int) $cita->numhistoria)
                ->value('paciente_id');
        }

        return $cita->paciente_sinhistoria_id !== null ? (int) $cita->paciente_sinhistoria_id : null;
    }

    /**
     * Reordenar la cola: **una** operación de sync, no N updates (el arrastre manda el movimiento).
     * Responde JSON al arrastre y redirige si el formulario no trae JS.
     */
    public function reordenar(Request $request, ReordenarCola $accion)
    {
        $contexto = $request->attributes->get('contexto_clinico');

        $datos = $request->validate([
            'record_id' => ['required', 'integer'],
            'from'      => ['required', 'integer'],
            'to'        => ['required', 'integer'],
            'fecha'     => ['required', 'date'],
        ]);

        $cita = Cola::where('id', $datos['record_id'])->firstOrFail();
        $this->citaDelContexto($request, $cita);

        $accion->ejecutar(
            $cita,
            (int) $datos['from'],
            (int) $datos['to'],
            (string) $datos['fecha'],
            [$contexto['reg_medico']],
            null,
            'web'
        );

        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('estado', 'Orden actualizado.');
    }
}
