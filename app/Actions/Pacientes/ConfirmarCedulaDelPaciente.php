<?php

namespace App\Actions\Pacientes;

use App\Models\Paciente;
use App\Models\PacienteIdentidad;
use App\Models\User;
use App\Paciente\NivelesDeIdentidad;
use InvalidArgumentException;

/**
 * **Confirmar la cédula de un paciente** (identidad nivel 2): la secretaria o el médico vieron la cédula
 * laminada en la consulta. Queda anotado quién, cuándo y desde qué consultorio (`reg_medico`, el tenant de
 * quien confirma), en `paciente_identidad` —no en `pacientes`, que se sincroniza con el escritorio—.
 *
 * La confirmación es de la **persona** (la cédula identifica al paciente en todos los consultorios), no del
 * consultorio; el `reg_medico` sólo deja constancia de dónde se hizo. Se guarda la cédula que se confirmó: si
 * después la ficha cambia de cédula, la confirmación ya no vale (`NivelesDeIdentidad::cedulaConfirmada`).
 * Confirmar de nuevo es idempotente: actualiza quién y cuándo.
 */
final class ConfirmarCedulaDelPaciente
{
    /**
     * @throws InvalidArgumentException si la ficha no tiene una cédula utilizable
     */
    public function ejecutar(Paciente $paciente, User $actor, ?string $regMedico = null, ?string $nota = null): PacienteIdentidad
    {
        if (IdentidadDePaciente::esSinCedula($paciente->cedula)) {
            throw new InvalidArgumentException('El paciente no tiene cédula registrada: cárgala en la ficha antes de confirmarla.');
        }

        $digitos = NivelesDeIdentidad::digitos($paciente->cedula);

        if ($digitos === '' || strlen($digitos) > 10) {
            throw new InvalidArgumentException('La cédula de la ficha no es válida: corrígela antes de confirmarla.');
        }

        $nota = trim((string) $nota);

        return PacienteIdentidad::updateOrCreate(
            ['paciente_id' => $paciente->id],
            [
                'reg_medico' => $regMedico,
                'cedula' => $digitos,
                'cedula_confirmada_en' => now(),
                'confirmada_por_user_id' => $actor->id,
                'nota' => $nota !== '' ? mb_substr($nota, 0, 255) : null,
            ],
        );
    }
}
