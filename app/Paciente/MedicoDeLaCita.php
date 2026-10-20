<?php

namespace App\Paciente;

use App\Models\Cola;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\Paciente;

/**
 * El médico con el que el paciente tiene la cita, para poder reprogramarla.
 *
 * Sale del vínculo `medico_pacientes` del mismo `reg_medico` y `numhistoria` (o del paciente, en una cita
 * sin historia). Si no hay vínculo, cae al primer médico de ese registro. Devuelve `null` cuando no se
 * puede resolver: el área del paciente prefiere decir «llama al consultorio» antes que editar la cita con
 * un contexto inventado.
 */
final class MedicoDeLaCita
{
    public static function de(Paciente $paciente, Cola $cita): ?Medico
    {
        $consulta = MedicoPaciente::where('paciente_id', $paciente->id);

        if ($cita->reg_medico) {
            $consulta->where('reg_medico', $cita->reg_medico);
        }

        if ($cita->numhistoria !== null) {
            $consulta->where('numhistoria', $cita->numhistoria);
        }

        $vinculo = $consulta->first();

        if ($vinculo !== null && $vinculo->medico_id) {
            return Medico::find($vinculo->medico_id);
        }

        return $cita->reg_medico
            ? Medico::where('reg_medico', $cita->reg_medico)->first()
            : null;
    }
}
