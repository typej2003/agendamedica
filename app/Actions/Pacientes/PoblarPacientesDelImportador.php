<?php

namespace App\Actions\Pacientes;

use App\Clinica\Pacientes\CamposDePaciente;
use App\Models\Historia;
use App\Models\MedicoPaciente;
use App\Models\Paciente;

/**
 * **Poblar `medico_pacientes` e `historias` desde la tabla `pacientes`**, después de importar un dump del
 * legado por el panel del `/admin` (`CargarSql`).
 *
 * El número de historia es único **por registro** (`historias` tiene `(reg_medico, numhistoria)` único), y
 * desde la Fase 1 del cambio de `reg_medico` el **vínculo también** es único **por consultorio**
 * (`(reg_medico, paciente_id)`, migración del 2026-10-20). El importador escribía el vínculo con el número
 * del dump y **después** creaba la historia: si el número ya era de otro paciente de ese mismo registro (un
 * dump cargado dos veces, o el import de pediatría sobre un consultorio que ya tenía pacientes), la fila de
 * `historias` fallaba por la clave única, el proceso se cortaba a la mitad **y el vínculo quedaba escrito**.
 * El resultado era el peor de los estados: dos pacientes con el mismo número en el mismo consultorio, sin
 * que ninguna pantalla pudiera saber cuál es el bueno — la agenda nombraba a uno y «Atender» abría al otro
 * (informe del usuario, 2026-10-19).
 *
 * La regla, ahora, es **mirar antes de escribir** y no dejar nunca un vínculo con un número ajeno:
 *
 *  - un número **de otro paciente** (en `historias` o en otro vínculo del mismo registro) se **omite** y se
 *    informa: no se le quita la historia a nadie y no se crea un duplicado. El paciente queda sin número,
 *    que es una salida real (la ficha ofrece asignárselo con `AsignarHistoria`, que numera uno nuevo);
 *  - un número **libre** deja el vínculo y la fila de `historias` (las dos, siempre: es la trampa 143 — un
 *    número que sólo vive en el vínculo no se puede atender);
 *  - el número de un paciente ya vinculado se respeta y se completa su historia.
 *
 * Todo en **una transacción**: o queda el paciente con su historia, o no queda nada.
 */
final class PoblarPacientesDelImportador
{
    /**
     * @param  iterable<int,Paciente>  $pacientes  los pacientes del dump para este registro
     * @return array{creados:int, existentes:int, omitidos: array<int,array{numhistoria:string, paciente_id:int, motivo:string}>}
     */
    public function ejecutar(int $medicoId, string $regMedico, iterable $pacientes): array
    {
        $creados = 0;
        $existentes = 0;
        $omitidos = [];

        \Illuminate\Support\Facades\DB::transaction(function () use ($medicoId, $regMedico, $pacientes, &$creados, &$existentes, &$omitidos) {
            foreach ($pacientes as $paciente) {
                // El número del dump; si no lo trae, el `id` del paciente (lo que hacía el importador).
                $numero = CamposDePaciente::historiaNumerica($paciente->numhistoria ?? null);

                if ($numero === null) {
                    $numero = (int) $paciente->id;
                }

                $texto = (string) $numero;

                // El número ya tiene dueño y no es este paciente: no se toca nada.
                $duenio = $this->duenioDelNumero($regMedico, $texto, (int) $paciente->id);

                if ($duenio !== null) {
                    $omitidos[] = [
                        'numhistoria' => $texto,
                        'paciente_id' => (int) $paciente->id,
                        'motivo'      => "el número {$texto} ya es del paciente {$duenio}",
                    ];

                    continue;
                }

                $yaEstaba = MedicoPaciente::where('reg_medico', $regMedico)
                    ->where('paciente_id', $paciente->id)
                    ->exists();

                // El vínculo es **del consultorio** (`(reg_medico, paciente_id)`, migración del 2026-10-20):
                // si ya existía se conserva su `medico_id` y sólo se actualiza la copia del número.
                $vinculo = MedicoPaciente::firstOrNew([
                    'reg_medico'  => $regMedico,
                    'paciente_id' => $paciente->id,
                ]);

                if (! $vinculo->exists) {
                    $vinculo->medico_id = $medicoId;
                }

                $vinculo->numhistoria = $texto;
                $vinculo->save();

                // `pacientes.reg_medico` = el tenant del **primer** vínculo del paciente (no se pisa el de
                // una ficha que ya era de otro consultorio).
                if (! $yaEstaba && trim((string) $paciente->reg_medico) === '') {
                    $paciente->forceFill(['reg_medico' => $regMedico])->save();
                }

                // La historia **siempre**, con la clave única del esquema: un número que sólo vive en el
                // vínculo no se puede atender (trampa 143).
                Historia::updateOrCreate(
                    ['reg_medico' => $regMedico, 'numhistoria' => $texto],
                    ['paciente_id' => (int) $paciente->id, 'medico_id' => $medicoId]
                );

                $yaEstaba ? $existentes++ : $creados++;
            }
        });

        return ['creados' => $creados, 'existentes' => $existentes, 'omitidos' => $omitidos];
    }

    /**
     * `pacientes.id` del **otro** paciente que ya tiene ese número en el registro, o `null` si está libre
     * (o si es de este mismo paciente). Mira las dos tablas donde vive el número.
     */
    private function duenioDelNumero(string $regMedico, string $numero, int $pacienteId): ?int
    {
        $enLaHistoria = Historia::where('reg_medico', $regMedico)
            ->where('numhistoria', $numero)
            ->whereNotNull('paciente_id')
            ->where('paciente_id', '!=', $pacienteId)
            ->value('paciente_id');

        if ($enLaHistoria !== null) {
            return (int) $enLaHistoria;
        }

        $enElVinculo = MedicoPaciente::where('reg_medico', $regMedico)
            ->where('numhistoria', $numero)
            ->where('paciente_id', '!=', $pacienteId)
            ->value('paciente_id');

        return $enElVinculo !== null ? (int) $enElVinculo : null;
    }
}
