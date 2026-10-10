<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Agenda\AtenderCita;
use App\Actions\Agenda\CobrarCita;
use App\Actions\Agenda\ConfirmarCita;
use App\Actions\Agenda\ReordenarCola;
use App\Http\Controllers\Clinica\Concerns\ResuelveLaCitaDelContexto;
use App\Http\Controllers\Controller;
use App\Models\Cola;
use Illuminate\Http\Request;

/**
 * Las acciones del mostrador sobre una cita —confirmar, atender, cobrar y reordenar la cola—, todas sobre
 * las **Actions compartidas** con el sync del móvil (PLAN-WEB.md, R3). El controlador solo valida que la
 * cita sea del contexto y traduce el resultado a un mensaje.
 */
class MostradorController extends Controller
{
    use ResuelveLaCitaDelContexto;

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
