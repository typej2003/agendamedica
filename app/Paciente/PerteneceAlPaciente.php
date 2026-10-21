<?php

namespace App\Paciente;

use App\Models\Cola;
use App\Models\MedicoPaciente;
use App\Models\Paciente;

/**
 * **¿Esta cita es de este paciente?** Es la comprobación que autoriza a verla, cancelarla o reprogramarla
 * desde «Mis citas» (2026-10-19). Sin ella, cambiar el id de la URL dejaría tocar la cita de otra persona:
 * las rutas responden 404, no 403, para no revelar que la cita existe.
 *
 * Se acepta por las dos formas en que `cola` se ancla al paciente: `paciente_sinhistoria_id` (una cita
 * reservada sin historia) o el vínculo `medico_pacientes` (`reg_medico` + `numhistoria`). **No se puede
 * buscar solo por `numhistoria`**: ese número es por médico y se repite entre consultorios.
 */
final class PerteneceAlPaciente
{
    public static function si(Paciente $paciente, Cola $cita): bool
    {
        if ($cita->paciente_sinhistoria_id !== null && (int) $cita->paciente_sinhistoria_id === (int) $paciente->id) {
            return true;
        }

        if ($cita->numhistoria === null || ! $cita->reg_medico) {
            return false;
        }

        return MedicoPaciente::where('paciente_id', $paciente->id)
            ->where('reg_medico', $cita->reg_medico)
            ->where('numhistoria', $cita->numhistoria)
            ->exists();
    }
}
