<?php

namespace App\Http\Controllers\Clinica;

use App\Actions\Notificaciones\EnviarRecordatorio;
use App\Clinica\Agenda\LectorDeAgenda;
use App\Clinica\Agenda\ParametrosDeAgenda;
use App\Clinica\Agenda\Vistas\EnvioVista;
use App\Http\Controllers\Clinica\Concerns\ResuelveLaCitaDelContexto;
use App\Http\Controllers\Controller;
use App\Models\Cola;
use App\Models\NotificacionCita;
use App\Notificaciones\Canal;
use App\Notificaciones\ExcepcionDeEnvio;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Los recordatorios de la agenda (WEB-2.6 y 2.7): el de **una** cita y el **envío del día**. El envío en
 * sí lo hace `EnviarRecordatorio`, la misma Action que usa el endpoint del app (PLAN-WEB.md, R3); el
 * texto de cada cita lo arma `RecordatoriosDeAgenda`.
 */
class RecordatorioController extends Controller
{
    use ResuelveLaCitaDelContexto;

    public function __construct(private LectorDeAgenda $lector)
    {
    }

    /**
     * Mandar el recordatorio de **una** cita, por el canal elegido (WEB-2.6).
     *
     * Es el camino del móvil (Paso 21.B) traído a la web: el diálogo ofrece los tres canales, el texto del
     * médico **de esa cita** y avisa si al paciente le falta el dato.
     *
     * Las citas confirmadas o atendidas no reciben recordatorio (regla de la wiki): el diálogo no se
     * ofrece y esto lo vuelve a comprobar, porque un POST se puede mandar a mano.
     */
    public function notificar(Request $request, Cola $cola, EnviarRecordatorio $accion)
    {
        $cita = $this->citaDelContexto($request, $cola);

        if ($cita->movida_escritorio) {
            return back()->with('error', $this->avisoDeCitaMovida());
        }

        if ((int) $cita->atendido === 1) {
            return back()->with('error', 'Esta cita ya está atendida: no se le manda el recordatorio.');
        }

        if (in_array((int) $cita->estado, [Cola::ESTADO_CONFIRMADA, Cola::ESTADO_CONFIRMADA_PACIENTE], true)) {
            return back()->with('error', 'Esta cita ya está confirmada: no hace falta recordársela.');
        }

        $datos = $request->validate([
            'canal'   => ['required', 'in:' . implode(',', Canal::todos())],
            'mensaje' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $notificacion = $accion->ejecutar($cita, $datos['canal'], $datos['mensaje'] ?? null, $request->user());
        } catch (ExcepcionDeEnvio|InvalidArgumentException $excepcion) {
            return back()->with('error', $excepcion->getMessage());
        }

        if ($notificacion->estado !== NotificacionCita::ESTADO_ENVIADA) {
            return back()->with('error', 'El proveedor no aceptó el mensaje. El intento quedó registrado.');
        }

        return back()->with('estado', 'Recordatorio enviado por ' . Canal::etiqueta($datos['canal']) . '.');
    }

    /** La ventana de **envío del día**: los pacientes de la jornada, el canal y el texto. */
    public function envio(Request $request)
    {
        $contexto = $request->attributes->get('contexto_clinico');
        $fecha = ParametrosDeAgenda::fechaDe($request->query('fecha'));
        $sedeId = ParametrosDeAgenda::sedeDe($request, $contexto);
        $sedes = $this->lector->sedes($contexto);

        $citas = $this->lector->citasParaRecordar($contexto, $sedes, $fecha, $sedeId);
        $canalPedido = $request->query('canal');

        return view('clinica.agenda.envio', [
            'vm' => new EnvioVista(
                $fecha,
                $sedeId ? $sedes->firstWhere('id', $sedeId) : null,
                $citas,
                $this->lector->mensajesPara($contexto, $citas, $sedes),
                // El SMS es el del escritorio (su ventana, y su columna `cola.sms`): es el que viene elegido.
                Canal::existe($canalPedido) ? $canalPedido : Canal::SMS,
            ),
        ]);
    }

    /**
     * Mandar el recordatorio a **los seleccionados** de la jornada (WEB-2.7).
     *
     * Va cita por cita a propósito: que a un paciente le falte el teléfono, o que el proveedor rechace un
     * mensaje, **no** puede abortar el resto del envío. Cada intento queda registrado igual
     * (`notificaciones_cita`), y lo que falló se le informa al usuario con el nombre del paciente.
     */
    public function enviarMasivo(Request $request, EnviarRecordatorio $accion)
    {
        $contexto = $request->attributes->get('contexto_clinico');

        $datos = $request->validate([
            'fecha'   => ['required', 'date'],
            'sede'    => ['nullable'],
            'canal'   => ['required', 'in:' . implode(',', Canal::todos())],
            'mensaje' => ['nullable', 'string', 'max:500'],
            'citas'   => ['required', 'array'],
            'citas.*' => ['integer'],
        ]);

        $enviados = 0;
        $fallidos = [];

        foreach ($datos['citas'] as $id) {
            $cita = Cola::find($id);

            // Tenancy: el POST puede traer cualquier id; solo se toca lo del registro del contexto.
            if (! $cita || $cita->reg_medico !== $contexto['reg_medico']) {
                continue;
            }

            try {
                $notificacion = $accion->ejecutar($cita, $datos['canal'], $datos['mensaje'] ?? null, $request->user());
            } catch (ExcepcionDeEnvio|InvalidArgumentException $excepcion) {
                $fallidos[] = $this->nombreDelPaciente($cita, $accion) . ': ' . $excepcion->getMessage();
                continue;
            }

            if ($notificacion->estado === NotificacionCita::ESTADO_ENVIADA) {
                $enviados++;
            } else {
                $fallidos[] = $this->nombreDelPaciente($cita, $accion) . ': el proveedor no aceptó el mensaje';
            }
        }

        $destino = redirect()->route('clinica.agenda.envio', [
            'fecha' => $datos['fecha'],
            'sede'  => $datos['sede'] ?: 'todas',
            'canal' => $datos['canal'],
        ])->with('estado', sprintf(
            '%d %s por %s.',
            $enviados,
            $enviados === 1 ? 'recordatorio enviado' : 'recordatorios enviados',
            Canal::etiqueta($datos['canal']),
        ));

        if ($fallidos !== []) {
            $destino = $destino->with('error', sprintf(
                '%d sin enviar — %s%s',
                count($fallidos),
                implode('; ', array_slice($fallidos, 0, 3)),
                count($fallidos) > 3 ? '…' : '',
            ));
        }

        return $destino;
    }

    /** El nombre del paciente de una cita, para decir **a quién** no se le pudo mandar el recordatorio. */
    private function nombreDelPaciente(Cola $cita, EnviarRecordatorio $accion): string
    {
        $paciente = $accion->pacienteDeLaCita($cita);

        return $paciente
            ? trim(($paciente->nombres ?? '') . ' ' . ($paciente->apellidos ?? ''))
            : 'Cita #' . $cita->id;
    }
}
