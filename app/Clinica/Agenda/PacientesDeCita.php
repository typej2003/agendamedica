<?php

namespace App\Clinica\Agenda;

use App\Models\Cola;
use App\Models\MedicoPaciente;
use App\Models\Paciente;
use Illuminate\Validation\ValidationException;

/**
 * Los pacientes a los que se les agenda una cita desde la web: el elegido del listado, la ficha que ya
 * exista con la cédula escrita o los datos mínimos de uno nuevo. **No escribe nada**: el alta la hace
 * `CrearPaciente` recién cuando la cita se guarda, para que cancelar no deje un paciente suelto (igual
 * que el móvil).
 */
class PacientesDeCita
{
    /** Un paciente del `reg_medico` del contexto; de otro médico no se muestra ni se confirma que exista. */
    public function delContexto(array $contexto, int $pacienteId): ?Paciente
    {
        $relacion = MedicoPaciente::where('medico_id', $contexto['medico']->id)
            ->where('paciente_id', $pacienteId)
            ->where('reg_medico', $contexto['reg_medico'])
            ->first();

        return $relacion !== null ? Paciente::find($relacion->paciente_id) : null;
    }

    public function numHistoriaDe(array $contexto, Paciente $paciente): ?int
    {
        $numhistoria = MedicoPaciente::where('medico_id', $contexto['medico']->id)
            ->where('paciente_id', $paciente->id)
            ->value('numhistoria');

        return $numhistoria !== null ? (int) $numhistoria : null;
    }

    /** La ficha del paciente de una cita: por `paciente_sinhistoria_id` o por el número de historia. */
    public function deLaCita(Cola $cita): ?Paciente
    {
        if ($cita->paciente_sinhistoria_id !== null) {
            return Paciente::find($cita->paciente_sinhistoria_id);
        }

        if ($cita->numhistoria === null) {
            return null;
        }

        $pacienteId = MedicoPaciente::where('reg_medico', $cita->reg_medico)
            ->where('numhistoria', $cita->numhistoria)
            ->value('paciente_id');

        return $pacienteId !== null ? Paciente::find($pacienteId) : null;
    }

    /**
     * A quién se le está agendando, **sin escribir nada**: el paciente elegido del listado o la ficha que
     * ya exista con la cédula nueva (el alta enlaza, no duplica).
     *
     * @return array{paciente: ?Paciente, numhistoria: ?int}
     */
    public function identificar(array $contexto, array $valores): array
    {
        if (! empty($valores['paciente_id'])) {
            $paciente = $this->delContexto($contexto, (int) $valores['paciente_id']);

            // De otro médico no se agenda desde acá (la búsqueda tampoco lo ofrece).
            abort_if($paciente === null, 404);

            return ['paciente' => $paciente, 'numhistoria' => $this->numHistoriaDe($contexto, $paciente)];
        }

        $cedula = trim((string) ($valores['nuevo_cedula'] ?? ''));
        if ($cedula === '') {
            throw ValidationException::withMessages([
                'paciente_id' => 'Elige un paciente de la lista o carga los datos de uno nuevo.',
            ]);
        }

        $paciente = Paciente::where('cedula', $cedula)->first();
        if ($paciente === null) {
            return ['paciente' => null, 'numhistoria' => null];
        }

        // La cédula ya existe: es la misma persona. Si ya es paciente de este médico, su número de
        // historia es el que va en la cita.
        return ['paciente' => $paciente, 'numhistoria' => $this->numHistoriaDe($contexto, $paciente)];
    }

    /** @return array<string,mixed> Las columnas del alta mínima de paciente (las mismas que el móvil). */
    public function columnasDelNuevo(array $valores): array
    {
        return [
            'cedula'    => trim((string) $valores['nuevo_cedula']),
            'nombres'   => trim((string) ($valores['nuevo_nombres'] ?? '')),
            'apellidos' => trim((string) ($valores['nuevo_apellidos'] ?? '')),
            'telefono'  => $this->texto($valores['nuevo_telefono'] ?? null),
            'email'     => $this->texto($valores['nuevo_email'] ?? null),
            'sexo'      => $this->texto($valores['nuevo_sexo'] ?? null),
        ];
    }

    /**
     * La ficha del paciente elegido, para mostrarla en el formulario. Si todavía no se guardó (alta nueva)
     * se arma con lo que se escribió, para que la repregunta por la cita pendiente no la pierda de vista.
     *
     * @return array{id: ?int, nombre: string, numhistoria: ?int}|null
     */
    public function ficha(?Paciente $paciente, ?int $numhistoria, array $valores = []): ?array
    {
        if ($paciente !== null) {
            return [
                'id'          => $paciente->id,
                'nombre'      => trim(($paciente->apellidos ?? '') . ', ' . ($paciente->nombres ?? ''), ', '),
                'numhistoria' => $numhistoria,
            ];
        }

        $nombres = trim((string) ($valores['nuevo_nombres'] ?? ''));
        $apellidos = trim((string) ($valores['nuevo_apellidos'] ?? ''));
        if ($nombres === '' && $apellidos === '') {
            return null;
        }

        return ['id' => null, 'nombre' => trim($apellidos . ', ' . $nombres, ', '), 'numhistoria' => null];
    }

    private function texto($valor): ?string
    {
        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }
}
