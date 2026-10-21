<?php

namespace App\Http\Controllers\Paciente\Concerns;

use App\Models\Cola;
use App\Models\Paciente;
use App\Paciente\CitaDelPaciente;
use App\Paciente\MisCitas;
use App\Paciente\PerteneceAlPaciente;
use Illuminate\Http\Request;

/**
 * Resuelve la ficha del paciente logueado y la cita que se quiere tocar, con la **comprobación de dueño**
 * en un solo lugar. Las rutas de «Mis citas» responden 404 (no 403) cuando la cita no es del paciente:
 * así no se revela que existe.
 */
trait ResuelveAlPaciente
{
    protected function paciente(Request $request): Paciente
    {
        $paciente = $request->user()?->paciente;

        abort_if($paciente === null, 404, 'Tu cuenta no tiene una ficha de paciente.');

        return $paciente;
    }

    protected function citaDelPaciente(Request $request, Cola $cita): CitaDelPaciente
    {
        $paciente = $this->paciente($request);

        abort_unless(PerteneceAlPaciente::si($paciente, $cita), 404);

        $encontrada = app(MisCitas::class)->de($paciente)
            ->first(fn (CitaDelPaciente $opcion) => $opcion->id() === (int) $cita->id);

        abort_if($encontrada === null, 404);

        return $encontrada;
    }
}
