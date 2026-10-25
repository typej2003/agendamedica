<?php

namespace App\Http\Controllers\Reservas;

use App\Http\Controllers\Controller;
use App\Models\Medico;
use App\Notificaciones\Telefono;
use App\Reservas\ConfirmarReservaPublica;
use App\Reservas\ReservaPendiente;
use App\Reservas\SolicitarCodigoDeReserva;
use Illuminate\Http\Request;

/**
 * La reserva pública en tres pasos: elegir horario y datos → verificar el teléfono con un código →
 * confirmar la cita.
 *
 * El horario y los datos viajan en el formulario, pero **la verdad la recalcula el servidor**: al enviar el
 * código y al confirmarlo se vuelve a preguntar por el primer lugar disponible de ese día. Si cambió, no se
 * reserva. El código se guarda hasheado en la sesión (ver `ReservaPendiente`), nunca en la base.
 */
class ReservaPublicaController extends Controller
{
    public function __construct(private ReservaPendiente $pendiente)
    {
    }

    /** Paso 1: el formulario de la ficha del médico envía acá los datos y el horario elegido. */
    public function enviarCodigo(Request $request, Medico $medico, SolicitarCodigoDeReserva $accion)
    {
        abort_unless($medico->is_active, 404);

        $datos = $request->validate([
            'horario'  => ['required', 'regex:/^\d{4}-\d{2}-\d{2}\|\d{2}:\d{2}\|\d+$/'],
            'cedula'   => ['required', 'string', 'max:20'],
            'nombres'  => ['required', 'string', 'max:100'],
            'apellidos' => ['required', 'string', 'max:100'],
            'telefono' => ['required', 'string', 'max:30'],
            'email'    => ['nullable', 'email', 'max:120'],
            'reenviar' => ['nullable', 'boolean'],
        ], $this->mensajes());

        [$fecha, $hora, $sede] = explode('|', $datos['horario']);

        $resultado = $accion->ejecutar($medico, [
            'fecha' => $fecha,
            'hora' => $hora,
            'office_id' => (int) $sede,
            'cedula' => $datos['cedula'],
            'nombres' => $datos['nombres'],
            'apellidos' => $datos['apellidos'],
            'telefono' => $datos['telefono'],
            'email' => $datos['email'] ?? null,
        ], $request->boolean('reenviar'));

        $mensaje = $resultado['reenviado']
            ? 'Te enviamos un código de 6 dígitos. Escríbelo para confirmar la cita.'
            : 'El horario quedó guardado. Usa el código que ya te enviamos.';

        return redirect()
            ->route('reservas.codigo', $medico)
            ->with('estado', $mensaje);
    }

    /** Paso 2: el formulario del código. */
    public function codigo(Request $request, Medico $medico)
    {
        $pendiente = $this->pendiente->para($medico);

        if ($pendiente === null) {
            return redirect()
                ->route('reservas.medico', $medico)
                ->with('error', 'La reserva venció. Elige el horario otra vez.');
        }

        return view('reservas.codigo', [
            'medico' => $medico,
            'pendiente' => $pendiente,
            'telefonoVisible' => $this->telefonoVisible((string) $pendiente['telefono']),
            // Solo en local: sin WhatsApp/Twilio configurados el código se simula y se muestra para poder
            // probar el flujo. En producción nunca se pinta.
            'codigoDesarrollo' => app()->environment('local') ? ($pendiente['codigo'] ?? null) : null,
        ]);
    }

    /** Paso 3: comprobar el código y crear la cita. */
    public function confirmar(Request $request, Medico $medico, ConfirmarReservaPublica $accion)
    {
        abort_unless($medico->is_active, 404);

        $datos = $request->validate([
            'codigo' => ['required', 'digits:6'],
        ], [
            'codigo.required' => 'Escribe el código de 6 dígitos.',
            'codigo.digits' => 'El código tiene que ser de 6 dígitos.',
        ]);

        $resultado = $accion->ejecutar($medico, $datos['codigo']);

        if (! $resultado['ok']) {
            return redirect()
                ->route('reservas.medico', $medico)
                ->with('error', $resultado['mensaje']);
        }

        $cita = $resultado['cita'];
        $opcion = $resultado['opcion'];
        $paciente = $resultado['paciente'];

        $request->session()->put('reservas.confirmada', [
            'cita_id' => $cita->id,
            'medico' => $medico->nombre_mostrar,
            'especialidades' => $medico->specialties()->where('activo', true)->orderBy('name')->pluck('name')->all(),
            'fecha' => $opcion->fecha,
            'dia' => $opcion->dia(),
            'hora' => $opcion->hora,
            'hora_legible' => $opcion->horaLegible(),
            'sede' => $opcion->sede,
            'direccion' => $opcion->direccion,
            'turno' => (int) $cita->numorden,
            'paciente' => $paciente !== null
                ? trim(($paciente->nombres ?? '') . ' ' . ($paciente->apellidos ?? ''))
                : '',
            'cedula' => $resultado['cedula'] ?? null,
            'telefono' => $resultado['telefono'] ?? null,
        ]);

        return redirect()->route('reservas.confirmada');
    }

    /** El comprobante de la reserva (vive en la sesión: es de un solo paciente y no se indexa). */
    public function confirmada(Request $request)
    {
        $datos = $request->session()->get('reservas.confirmada');

        if (! is_array($datos)) {
            return redirect()->route('reservas.catalogo');
        }

        return view('reservas.confirmada', ['reserva' => $datos]);
    }

    /** @return array<string,string> */
    private function mensajes(): array
    {
        return [
            'horario.required' => 'Elige uno de los horarios que se muestran.',
            'horario.regex' => 'El horario elegido no es válido. Vuelve a elegirlo.',
            'cedula.required' => 'Escribe tu cédula.',
            'nombres.required' => 'Escribe tu nombre.',
            'apellidos.required' => 'Escribe tu apellido.',
            'telefono.required' => 'Escribe tu teléfono: te enviaremos el código ahí.',
            'email.email' => 'Revisa el correo: no tiene forma de correo.',
        ];
    }

    private function telefonoVisible(string $telefono): string
    {
        $mostrar = Telefono::mostrar($telefono);

        if (strlen($telefono) < 8) {
            return $mostrar;
        }

        return '+' . substr($telefono, 0, 2) . ' ' . substr($telefono, 2, 3) . ' *** ' . substr($telefono, -4);
    }
}
