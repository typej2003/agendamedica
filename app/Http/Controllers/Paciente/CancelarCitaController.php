<?php

namespace App\Http\Controllers\Paciente;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Paciente\Concerns\ResuelveAlPaciente;
use App\Models\Cola;
use App\Paciente\CancelarCitaDelPaciente;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Cancelar una cita desde «Mis citas». El GET es la confirmación y el POST la ejecuta: nunca se borra con
 * un enlace suelto ni sin volver a comprobar la regla de las 24 horas.
 */
class CancelarCitaController extends Controller
{
    use ResuelveAlPaciente;

    public function confirmar(Request $request, Cola $cita)
    {
        $citaDelPaciente = $this->citaDelPaciente($request, $cita);

        if (! $citaDelPaciente->puedeGestionarse()) {
            return redirect()
                ->route('paciente.citas')
                ->with('error', $citaDelPaciente->aviso);
        }

        return view('paciente.cancelar', ['cita' => $citaDelPaciente]);
    }

    public function guardar(Request $request, Cola $cita, CancelarCitaDelPaciente $accion)
    {
        $citaDelPaciente = $this->citaDelPaciente($request, $cita);

        try {
            $accion->ejecutar($citaDelPaciente);
        } catch (InvalidArgumentException $e) {
            return redirect()
                ->route('paciente.citas')
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('paciente.citas')
            ->with('estado', 'Cita cancelada. Puedes reservar otra cuando quieras.');
    }
}
