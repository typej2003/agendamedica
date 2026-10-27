<?php

namespace App\Clinica\Agenda;

use App\Actions\Pacientes\IdentidadDePaciente;
use App\Clinica\Pacientes\DuenoDeLaHistoria;
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
        // El vínculo es **del consultorio** (`(reg_medico, paciente_id)`), no del médico: el que lo creó
        // pudo ser otro doctor del mismo centro o el centro mismo.
        $relacion = MedicoPaciente::where('reg_medico', $contexto['reg_medico'])
            ->where('paciente_id', $pacienteId)
            ->first();

        return $relacion !== null ? Paciente::find($relacion->paciente_id) : null;
    }

    public function numHistoriaDe(array $contexto, Paciente $paciente): ?int
    {
        $numhistoria = MedicoPaciente::where('reg_medico', $contexto['reg_medico'])
            ->where('paciente_id', $paciente->id)
            ->value('numhistoria');

        return $numhistoria !== null ? (int) $numhistoria : null;
    }

    /**
     * La ficha del paciente de una cita: por `paciente_sinhistoria_id` o por el número de historia,
     * resuelto **con la regla única** (`DuenoDeLaHistoria`) y dentro del registro de la cita.
     *
     * Es la misma regla que la agenda y la consulta: con dos pacientes en el mismo número, el formulario
     * de la cita no puede abrir la ficha de uno y la consulta la del otro.
     */
    public function deLaCita(Cola $cita): ?Paciente
    {
        if ($cita->paciente_sinhistoria_id !== null) {
            return Paciente::find($cita->paciente_sinhistoria_id);
        }

        if ($cita->numhistoria === null) {
            return null;
        }

        $pacienteId = (new DuenoDeLaHistoria())->de($cita->reg_medico, $cita->numhistoria);

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
        if (trim((string) ($valores['nuevo_nombres'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'paciente_id' => 'Elige un paciente de la lista o carga los datos de uno nuevo.',
            ]);
        }

        // Sin cédula (vacía o `S.C.`) no se enlaza con nadie: es una ficha nueva (`CrearPaciente`)… salvo que sea la que este mismo
        // consultorio acaba de crear con los mismos datos (el doble clic en «Guardar»: sin esto nacen dos fichas y dos citas).
        $paciente = IdentidadDePaciente::esSinCedula($cedula) ? $this->fichaRecienCreada($contexto, $valores) : Paciente::where('cedula', $cedula)->first();
        if ($paciente === null) {
            return ['paciente' => null, 'numhistoria' => null];
        }

        // La cédula ya existe: es la misma persona. Si ya es paciente de este médico, su número de
        // historia es el que va en la cita.
        return ['paciente' => $paciente, 'numhistoria' => $this->numHistoriaDe($contexto, $paciente)];
    }

    /** La ficha sin cédula que este consultorio creó hace pocos minutos con el mismo nombre, apellido y teléfono, o `null`. */
    private function fichaRecienCreada(array $contexto, array $valores): ?Paciente
    {
        $nombres = trim((string) ($valores['nuevo_nombres'] ?? ''));
        $apellidos = trim((string) ($valores['nuevo_apellidos'] ?? ''));
        $telefono = $this->texto($valores['nuevo_telefono'] ?? null);

        if ($nombres === '' || $apellidos === '' || $telefono === null) {
            return null;
        }

        return Paciente::query()
            ->where('nombres', $nombres)
            ->where('apellidos', $apellidos)
            ->where('telefono', $telefono)
            ->where('created_at', '>=', now()->subMinutes(10))
            ->whereIn('id', MedicoPaciente::where('reg_medico', $contexto['reg_medico'])->select('paciente_id'))
            ->orderByDesc('id')
            ->first();
    }

    /** @return array<string,mixed> Las columnas del alta mínima de paciente (las mismas que el móvil). */
    public function columnasDelNuevo(array $valores): array
    {
        return [
            'cedula'    => $this->texto($valores['nuevo_cedula'] ?? null),
            'nombres'   => trim((string) ($valores['nuevo_nombres'] ?? '')),
            'apellidos' => trim((string) ($valores['nuevo_apellidos'] ?? '')),
            'telefono'  => $this->texto($valores['nuevo_telefono'] ?? null),
            'email'     => $this->texto($valores['nuevo_email'] ?? null),
            'sexo'      => $this->texto($valores['nuevo_sexo'] ?? null),
            'fnacimiento' => $this->texto($valores['nuevo_fnacimiento'] ?? null),
            'nac'         => $this->texto($valores['nuevo_nac'] ?? null),
            'lnacimiento' => $this->texto($valores['nuevo_lnacimiento'] ?? null),
            'direccion'   => $this->texto($valores['nuevo_direccion'] ?? null),
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
