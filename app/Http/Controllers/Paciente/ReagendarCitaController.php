<?php

namespace App\Http\Controllers\Paciente;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Paciente\Concerns\ResuelveAlPaciente;
use App\Models\Cola;
use App\Paciente\PuedeGestionarSuCita;
use App\Paciente\ReagendarCitaDelPaciente;
use App\Reservas\DisponibilidadPublica;
use App\Reservas\OpcionDeReserva;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Reprogramar una cita desde «Mis citas»: se elige otro de los próximos horarios del mismo médico y se
 * mueve la misma fila. La comprobación de que el horario siga libre y el candado viven en
 * `ReagendarCitaDelPaciente`; acá sólo se traduce HTTP.
 */
class ReagendarCitaController extends Controller
{
    use ResuelveAlPaciente;

    public function formulario(Request $request, Cola $cita, DisponibilidadPublica $disponibilidad)
    {
        $citaDelPaciente = $this->citaDelPaciente($request, $cita);

        if (! $citaDelPaciente->puedeGestionarse()) {
            return redirect()
                ->route('paciente.citas')
                ->with('error', $citaDelPaciente->aviso);
        }

        return view('paciente.reprogramar', [
            'cita' => $citaDelPaciente,
            // El horario nuevo también respeta las 24 horas: si no, la cita quedaría otra vez dentro de
            // la ventana y no se podría volver a gestionar.
            'opciones' => array_values(array_filter(
                $disponibilidad->opciones($citaDelPaciente->medico),
                fn (OpcionDeReserva $opcion) => PuedeGestionarSuCita::opcionPermitida($opcion),
            )),
        ]);
    }

    public function guardar(Request $request, Cola $cita, ReagendarCitaDelPaciente $accion)
    {
        $datos = $request->validate([
            'horario' => [
                'required',
                'regex:/^\d{4}-\d{2}-\d{2}\|\d{2}:\d{2}\|\d+$/',
                function (string $attribute, string $valor, callable $fail) {
                    [$fecha, $hora] = explode('|', $valor);
                    [$anio, $mes, $dia] = array_map('intval', explode('-', $fecha));

                    if (! checkdate($mes, $dia, $anio)) {
                        $fail('El día elegido no existe. Vuelve a elegirlo.');
                    }

                    [$horas, $minutos] = array_map('intval', explode(':', $hora));

                    if ($horas > 23 || $minutos > 59) {
                        $fail('La hora elegida no es válida. Vuelve a elegirla.');
                    }
                },
            ],
        ], [
            'horario.required' => 'Elige uno de los horarios que se muestran.',
            'horario.regex' => 'El horario elegido no es válido. Vuelve a elegirlo.',
        ]);

        $citaDelPaciente = $this->citaDelPaciente($request, $cita);

        [$fecha, $hora, $officeId] = explode('|', $datos['horario']);

        try {
            $accion->ejecutar($citaDelPaciente, $fecha, $hora, (int) $officeId);
        } catch (InvalidArgumentException $e) {
            return redirect()
                ->route('paciente.citas.reprogramar', $cita)
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('paciente.citas')
            ->with('estado', 'Cita reprogramada. Revisa la fecha y la hora nuevas.');
    }
}
