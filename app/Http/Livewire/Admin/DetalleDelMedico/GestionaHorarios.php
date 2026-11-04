<?php

namespace App\Http\Livewire\Admin\DetalleDelMedico;

use App\Actions\Consultorio\GuardarBloqueHorario;
use App\Models\Office;
use App\Models\OfficeSchedule;
use Illuminate\Validation\ValidationException;

/**
 * **Horarios y cupos de los consultorios**, administrados desde el detalle del médico.
 *
 * Cada fila es un bloque de `office_schedules` («los lunes de 8:00 a 12:00, hasta 20 pacientes»). La regla
 * vive en `GuardarBloqueHorario`, la misma Action que `/clinica` y el panel de sedes: un bloque que se pisa
 * con otro del mismo consultorio se rechaza; con otro consultorio del médico se guarda y se avisa.
 */
trait GestionaHorarios
{
    /** Formulario de bloque abierto (null = cerrado). */
    public $horarioForm = false;

    public $horarioConsultorioId = null;
    public $horarioBloqueId = null;

    public $horarioDia = '';
    public $horarioInicio = '';
    public $horarioFin = '';
    public $horarioCupo = '';

    /** Avisos de solape con otro consultorio del médico (se guardó igual). */
    public $horarioAvisos = [];

    public function abrirHorarioNuevo(int $consultorioId): void
    {
        $this->consultorioDelMedico($consultorioId);

        $this->resetErrorBag();
        $this->horarioConsultorioId = $consultorioId;
        $this->horarioBloqueId = null;
        $this->horarioDia = '';
        $this->horarioInicio = '';
        $this->horarioFin = '';
        $this->horarioCupo = '';
        $this->horarioAvisos = [];
        $this->horarioForm = true;
    }

    public function abrirHorarioEditar(int $consultorioId, int $bloqueId): void
    {
        $bloque = $this->bloqueDelConsultorio($consultorioId, $bloqueId);

        $this->resetErrorBag();
        $this->horarioConsultorioId = $consultorioId;
        $this->horarioBloqueId = $bloque->id;
        $this->horarioDia = (string) $bloque->dia_semana;
        $this->horarioInicio = substr((string) $bloque->hora_inicio, 0, 5);
        $this->horarioFin = substr((string) $bloque->hora_fin, 0, 5);
        $this->horarioCupo = $bloque->cupo === null ? '' : (string) $bloque->cupo;
        $this->horarioAvisos = [];
        $this->horarioForm = true;
    }

    public function cerrarHorario(): void
    {
        $this->horarioForm = false;
        $this->horarioConsultorioId = null;
        $this->horarioBloqueId = null;
        $this->horarioDia = '';
        $this->horarioInicio = '';
        $this->horarioFin = '';
        $this->horarioCupo = '';
        $this->horarioAvisos = [];
        $this->resetErrorBag();
    }

    public function guardarHorario(GuardarBloqueHorario $accion): void
    {
        $hora = 'regex:/^([01]\d|2[0-3]):[0-5]\d$/';

        $this->validate([
            'horarioDia' => ['required', 'integer', 'min:1', 'max:7'],
            'horarioInicio' => ['required', $hora],
            'horarioFin' => ['required', $hora, 'after:horarioInicio'],
            'horarioCupo' => ['nullable', 'integer', 'min:1', 'max:65535'],
        ], [
            'horarioDia.required' => 'Elige el día de la semana.',
            'horarioInicio.required' => 'Pon la hora en que empieza el bloque.',
            'horarioInicio.regex' => 'La hora de inicio no es válida.',
            'horarioFin.required' => 'Pon la hora en que termina el bloque.',
            'horarioFin.regex' => 'La hora de fin no es válida.',
            'horarioFin.after' => 'La hora de fin tiene que ser posterior a la de inicio.',
            'horarioCupo.min' => 'El cupo, si lo pones, tiene que ser de al menos 1 paciente.',
        ], [
            'horarioDia' => 'día',
            'horarioInicio' => 'hora de inicio',
            'horarioFin' => 'hora de fin',
            'horarioCupo' => 'cupo',
        ]);

        $consultorio = $this->consultorioDelMedico((int) $this->horarioConsultorioId);
        $bloque = $this->horarioBloqueId
            ? $this->bloqueDelConsultorio((int) $this->horarioConsultorioId, (int) $this->horarioBloqueId)
            : null;

        try {
            $aviso = $accion->ejecutar([
                'dia_semana' => (int) $this->horarioDia,
                'hora_inicio' => $this->horarioInicio,
                'hora_fin' => $this->horarioFin,
                'cupo' => $this->horarioCupo === '' ? null : (int) $this->horarioCupo,
            ], $consultorio, (string) $this->medico->reg_medico, $bloque);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $campo => $mensajes) {
                $this->addError($campo === 'hora_inicio' ? 'horarioInicio' : $campo, $mensajes[0]);
            }

            return;
        }

        $this->horarioAvisos = $aviso !== null ? [$aviso] : [];
        session()->flash('message', $bloque
            ? 'Bloque actualizado: ' . $bloque->fresh()->nombreDelDia() . ' ' . $bloque->fresh()->rango() . '.'
            : 'Bloque agregado: ' . OfficeSchedule::DIAS[(int) $this->horarioDia] . ' '
                . substr($this->horarioInicio, 0, 5) . '–' . substr($this->horarioFin, 0, 5) . '.');
        $this->cerrarHorario();
    }

    public function quitarHorario(int $consultorioId, int $bloqueId): void
    {
        $bloque = $this->bloqueDelConsultorio($consultorioId, $bloqueId);
        $texto = $bloque->nombreDelDia() . ' ' . $bloque->rango();
        $bloque->delete();

        session()->flash('message', "Bloque {$texto} quitado: esas horas dejan de contarse como jornada.");
    }

    /** Un consultorio **de este médico**; de otro, 404. */
    private function consultorioDelMedico(int $id): Office
    {
        return Office::delMedico((string) $this->medico->reg_medico, $this->medico->id)
            ->whereKey($id)
            ->firstOrFail();
    }

    private function bloqueDelConsultorio(int $consultorioId, int $bloqueId): OfficeSchedule
    {
        return $this->consultorioDelMedico($consultorioId)->schedules()->whereKey($bloqueId)->firstOrFail();
    }
}
