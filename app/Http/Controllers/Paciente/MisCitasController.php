<?php

namespace App\Http\Controllers\Paciente;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Paciente\Concerns\ResuelveAlPaciente;
use App\Paciente\MisCitas;
use Illuminate\Http\Request;

/**
 * **Mis citas**: el inicio del paciente (2026-10-19). Lista sus próximas citas y su historial, con las
 * acciones de cancelar y reprogramar cuando la regla de `PuedeGestionarSuCita` lo permite.
 */
class MisCitasController extends Controller
{
    use ResuelveAlPaciente;

    public function index(Request $request, MisCitas $misCitas)
    {
        $paciente = $this->paciente($request);
        $citas = $misCitas->de($paciente);

        return view('paciente.citas', [
            'paciente' => $paciente,
            'proximas' => $misCitas->proximas($citas),
            'pasadas' => $misCitas->pasadas($citas),
        ]);
    }
}
