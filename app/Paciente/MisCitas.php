<?php

namespace App\Paciente;

use App\Clinica\Agenda\ArmadorDeAgenda;
use App\Models\MedicalCenter;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\Paciente;
use Illuminate\Support\Collection;

/**
 * **Todas las citas de un paciente**, para «Mis citas» (2026-10-19).
 *
 * El vínculo real es `medico_pacientes`: el `numhistoria` es **por médico**, así que no se puede buscar
 * por un número suelto ni por el paciente en `cola` (esa tabla no tiene `paciente_id`). Por cada vínculo se
 * leen las citas de ese `reg_medico` con el `ArmadorDeAgenda` —el mismo que usa la agenda, que resuelve la
 * sede de las citas viejas— y se deduplican por id (un paciente con dos médicos del mismo consultorio
 * puede ver la misma fila por los dos lados).
 */
final class MisCitas
{
    public function __construct(private ArmadorDeAgenda $armador)
    {
    }

    /** @return Collection<int,CitaDelPaciente> de la más reciente a la más vieja. */
    public function de(Paciente $paciente): Collection
    {
        $citas = [];

        $vinculos = MedicoPaciente::where('paciente_id', $paciente->id)->get();

        foreach ($vinculos as $vinculo) {
            $medico = Medico::find($vinculo->medico_id);

            if (! $medico || ! $vinculo->reg_medico) {
                continue;
            }

            $numHistoria = $vinculo->numhistoria !== null && $vinculo->numhistoria !== ''
                ? (int) $vinculo->numhistoria
                : null;

            foreach ($this->armador->citasDelPaciente($vinculo->reg_medico, $numHistoria, (int) $paciente->id) as $cita) {
                if (isset($citas[$cita->id])) {
                    continue;
                }

                $citas[$cita->id] = new CitaDelPaciente(
                    $cita,
                    $medico,
                    MedicalCenter::find($cita->centroId),
                    PuedeGestionarSuCita::aviso($cita),
                );
            }
        }

        return collect($citas)
            ->sortByDesc(fn (CitaDelPaciente $cita) => $cita->inicio()->timestamp)
            ->values();
    }

    /** @param Collection<int,CitaDelPaciente> $citas */
    public function proximas(Collection $citas): Collection
    {
        return $citas->filter(fn (CitaDelPaciente $cita) => $cita->proxima())
            ->sortBy(fn (CitaDelPaciente $cita) => $cita->inicio()->timestamp)
            ->values();
    }

    /** @param Collection<int,CitaDelPaciente> $citas */
    public function pasadas(Collection $citas): Collection
    {
        return $citas->filter(fn (CitaDelPaciente $cita) => $cita->pasada())->values();
    }
}
