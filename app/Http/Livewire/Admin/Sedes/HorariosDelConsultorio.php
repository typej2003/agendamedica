<?php

namespace App\Http\Livewire\Admin\Sedes;

use App\Actions\Consultorio\GuardarBloqueHorario;
use App\Models\MedicalCenter;
use App\Models\Medico;
use App\Models\Office;
use App\Models\OfficeSchedule;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Panel · **Horarios y cupos de un consultorio** (`/admin/medicos/{medico}/sedes/{sede}/consultorios/{consultorio}/horarios`).
 *
 * Cada fila es un bloque de `office_schedules` («los lunes de 8:00 a 12:00, hasta 20 pacientes»): es lo que arma
 * la jornada de la agenda y contra lo que se mide el cupo. Las reglas son las de `GuardarBloqueHorario` (la misma
 * Action que `/clinica`): un bloque que se pisa con otro **del mismo consultorio** se rechaza; con otro consultorio
 * del médico se guarda y se avisa. El alta acepta varios días a la vez («lunes a viernes»), todo o nada.
 *
 * Quitar un bloque **lo borra** (como en `/clinica`): no es dato clínico, las citas que caían ahí siguen existiendo
 * y el modelo deja rastro en `sync_changes` para que el teléfono lo deje de mostrar.
 */
class HorariosDelConsultorio extends Component
{
    use ConfiguraUnMedico;

    public MedicalCenter $sede;
    public Office $consultorio;

    /** Ventana abierta: `form` | `quitar` | null */
    public $modal = null;
    public $bloqueId = null;

    /** Alta: los días encendidos (uno o varios). Edición: el día del bloque. */
    public $dias = [];
    public $dia_semana = '';
    public $hora_inicio = '';
    public $hora_fin = '';
    public $cupo = '';

    /** Los avisos de solape con otro consultorio del médico (se guardó igual). */
    public $avisos = [];

    public function mount(Medico $medico, MedicalCenter $sede, Office $consultorio)
    {
        $this->autorizar();
        $this->medico = $medico;
        $this->sede = $sede;
        $this->tenantOFallar();
        // De otro médico o de otra sede: 404.
        $this->consultorio = $this->lector()->consultorio($medico, $sede, $consultorio->id);
    }

    public function render()
    {
        return view('livewire.admin.sedes.horarios-del-consultorio', [
            'nombre'       => $this->nombreDelMedico(),
            'bloques'      => $this->consultorio->schedules()->orderBy('dia_semana')->orderBy('hora_inicio')->get(),
            'nombresDeDias' => OfficeSchedule::DIAS,
            'seleccionado' => $this->bloqueId ? $this->bloque((int) $this->bloqueId) : null,
        ]);
    }

    public function abrirAgregar()
    {
        $this->limpiar();
        $this->modal = 'form';
    }

    public function abrirEditar(int $id)
    {
        $bloque = $this->bloque($id);

        $this->limpiar();
        $this->bloqueId = $bloque->id;
        $this->dia_semana = (string) $bloque->dia_semana;
        $this->hora_inicio = substr((string) $bloque->hora_inicio, 0, 5);
        $this->hora_fin = substr((string) $bloque->hora_fin, 0, 5);
        $this->cupo = $bloque->cupo === null ? '' : (string) $bloque->cupo;
        $this->modal = 'form';
    }

    public function guardar(GuardarBloqueHorario $accion)
    {
        $tenant = $this->tenantOFallar();
        $consultorio = $this->consultorioVigente();
        $bloque = $this->bloqueId ? $this->bloque((int) $this->bloqueId) : null;

        // El formato, como en `/clinica` (`HorarioController::guardar`); el solape lo decide la Action.
        $hora = 'regex:/^([01]\d|2[0-3]):[0-5]\d$/';
        $this->validate([
            'dia_semana'  => $bloque ? ['required', 'integer', 'min:1', 'max:7'] : ['nullable'],
            'dias'        => $bloque ? ['nullable', 'array'] : ['required', 'array', 'min:1', 'max:7'],
            'dias.*'      => ['integer', 'min:1', 'max:7'],
            'hora_inicio' => ['required', $hora],
            'hora_fin'    => ['required', $hora, 'after:hora_inicio'],
            'cupo'        => ['nullable', 'integer', 'min:1', 'max:65535'],
        ], [
            'dia_semana.required'  => 'Elige el día de la semana.',
            'dias.required'        => 'Elige al menos un día de la semana.',
            'dias.min'             => 'Elige al menos un día de la semana.',
            'hora_inicio.required' => 'Pon la hora en que empieza el bloque.',
            'hora_inicio.regex'    => 'La hora de inicio no es válida.',
            'hora_fin.required'    => 'Pon la hora en que termina el bloque.',
            'hora_fin.regex'       => 'La hora de fin no es válida.',
            'hora_fin.after'       => 'La hora de fin tiene que ser posterior a la de inicio.',
            'cupo.min'             => 'El cupo, si lo pones, tiene que ser de al menos 1 paciente.',
        ]);

        $datos = [
            'hora_inicio' => $this->hora_inicio,
            'hora_fin'    => $this->hora_fin,
            'cupo'        => $this->cupo === '' || $this->cupo === null ? null : (int) $this->cupo,
        ];

        try {
            if ($bloque !== null) {
                $aviso = $accion->ejecutar(['dia_semana' => (int) $this->dia_semana] + $datos, $consultorio, $tenant, $bloque);
                $avisos = $aviso !== null ? [$aviso] : [];
                $estado = 'Bloque actualizado: ' . $bloque->fresh()->nombreDelDia() . ' ' . $bloque->fresh()->rango() . '.';
            } else {
                $dias = array_values(array_unique(array_map('intval', (array) $this->dias)));
                sort($dias);
                if (count($dias) === 1) {
                    $aviso = $accion->ejecutar(['dia_semana' => $dias[0]] + $datos, $consultorio, $tenant);
                    $avisos = $aviso !== null ? [$aviso] : [];
                } else {
                    $avisos = $accion->ejecutarVarios($datos, $dias, $consultorio, $tenant);
                }
                $estado = count($dias) === 1
                    ? 'Bloque agregado: ' . OfficeSchedule::DIAS[$dias[0]] . '.'
                    : count($dias) . ' bloques agregados: ' . implode(', ', array_map(fn ($d) => mb_strtolower(OfficeSchedule::DIAS[$d]), $dias)) . '.';
            }
        } catch (ValidationException $e) {
            $this->mostrarErrores($e);

            return;
        }

        session()->flash('message', $estado);
        $this->cerrarModal();
        $this->avisos = $avisos;
    }

    public function abrirQuitar(int $id)
    {
        $bloque = $this->bloque($id);

        $this->limpiar();
        $this->bloqueId = $bloque->id;
        $this->modal = 'quitar';
    }

    public function confirmarQuitar()
    {
        $bloque = $this->bloque((int) $this->bloqueId);
        $texto = $bloque->nombreDelDia() . ' ' . $bloque->rango();
        $bloque->delete();

        session()->flash('message', "Bloque {$texto} quitado: esas horas dejan de contarse como jornada.");
        $this->cerrarModal();
    }

    public function cerrarModal()
    {
        $this->limpiar();
        $this->modal = null;
    }

    /** El consultorio vuelto a leer dentro del médico y la sede (el de la propiedad viaja en el payload). */
    private function consultorioVigente(): Office
    {
        return $this->lector()->consultorio($this->medico, $this->sede, $this->consultorio->id);
    }

    /** Un bloque **de este consultorio**; otro, 404. */
    private function bloque(int $id): OfficeSchedule
    {
        return $this->consultorioVigente()->schedules()->whereKey($id)->firstOrFail();
    }

    private function limpiar(): void
    {
        $this->resetErrorBag();
        $this->bloqueId = null;
        $this->dias = [];
        $this->dia_semana = '';
        $this->hora_inicio = '';
        $this->hora_fin = '';
        $this->cupo = '';
        $this->avisos = [];
    }
}
