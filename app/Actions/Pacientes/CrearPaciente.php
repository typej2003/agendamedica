<?php

namespace App\Actions\Pacientes;

use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\Paciente;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Alta de un paciente: la ficha compartida (`pacientes`) y su vínculo con el médico
 * (`medico_pacientes`).
 *
 * La regla vive **acá y en un solo lugar**, y la usan las dos superficies: la web (la secretaría
 * cargando la cita) y el sync del móvil (una creación de `pacientes`), igual que las Actions de
 * agenda (PLAN-WEB.md, R3).
 *
 * Tres cosas que no son obvias y **son** la regla:
 *
 *  - **Un paciente es uno solo para todos los médicos** (`pacientes`); lo que cambia por médico es
 *    el vínculo (`medico_pacientes`). Si la cédula ya existe, se **enlaza**: no se duplica la ficha
 *    (es la clave con la que el legado reconoce a la misma persona entre consultorios).
 *  - Al enlazar, **solo se completan los campos vacíos** de la ficha ajena. Pisarla con lo que
 *    escribió la secretaría sería perder información de otro médico.
 *  - **No se crea `historia`**: el `numhistoria` lo asigna el API cuando la historia se completa
 *    (decisión del 2026-10-09), y hasta entonces el vínculo queda con `numhistoria` nulo y la cita
 *    se ancla por `cola.paciente_sinhistoria_id`.
 */
final class CrearPaciente
{
    /**
     * @param  array<string,mixed>  $columnas  Columnas ya validadas de `pacientes`. La cédula, los
     *                                         nombres y los apellidos son obligatorios: son lo que
     *                                         identifica a la persona.
     * @param  string  $regMedico  El registro con el que queda vinculado (el del contexto en la
     *                             web, el del médico autenticado en el sync).
     *
     * @throws InvalidArgumentException si falta la cédula.
     */
    public function ejecutar(array $columnas, Medico $medico, string $regMedico): Paciente
    {
        if (trim((string) ($columnas['cedula'] ?? '')) === '') {
            throw new InvalidArgumentException('La cédula es obligatoria para dar de alta un paciente.');
        }

        return DB::transaction(function () use ($columnas, $medico, $regMedico) {
            $paciente = Paciente::where('cedula', $columnas['cedula'])->first();

            if ($paciente) {
                $completar = [];
                foreach ($columnas as $columna => $valor) {
                    if ($valor !== null && $paciente->{$columna} === null) {
                        $completar[$columna] = $valor;
                    }
                }
                if ($completar !== []) {
                    $paciente->fill($completar)->save();
                }
            } else {
                $paciente = Paciente::create($columnas);
            }

            MedicoPaciente::firstOrCreate(
                ['medico_id' => $medico->id, 'paciente_id' => $paciente->id],
                ['reg_medico' => $regMedico, 'numhistoria' => null],
            );

            return $paciente;
        });
    }
}
