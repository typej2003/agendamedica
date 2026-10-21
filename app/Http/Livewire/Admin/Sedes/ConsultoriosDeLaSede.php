<?php

namespace App\Http\Livewire\Admin\Sedes;

use App\Actions\Consultorio\GuardarConsultorio;
use App\Models\MedicalCenter;
use App\Models\Medico;
use App\Models\Office;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Panel · **Consultorios del médico en una sede** (`/admin/medicos/{medico}/sedes/{sede}/consultorios`).
 *
 * Alta y edición por `GuardarConsultorio` (la misma regla que `/clinica`): el `reg_medico` es siempre el del
 * médico de la ruta, nunca del formulario. **Quitar es dar de baja** (`activo = false`): el consultorio define la
 * modalidad, la duración y los bloques de las citas que ya tiene, así que no se borra; se puede reactivar.
 */
class ConsultoriosDeLaSede extends Component
{
    use ConfiguraUnMedico;

    public MedicalCenter $sede;

    public $verInactivos = false;

    /** Ventana abierta: `form` | `quitar` | null */
    public $modal = null;
    public $consultorioId = null;

    public $office_number = '';
    public $phone = '';
    public $modalidad = Office::MODALIDAD_ORDEN;
    public $duracion_cita = '';

    public function mount(Medico $medico, MedicalCenter $sede)
    {
        $this->autorizar();
        $this->medico = $medico;
        $this->sede = $sede;
        $this->tenantOFallar();
    }

    public function render()
    {
        $seleccionado = $this->consultorioId ? $this->consultorio((int) $this->consultorioId) : null;

        return view('livewire.admin.sedes.consultorios-de-la-sede', [
            'nombre'       => $this->nombreDelMedico(),
            'consultorios' => $this->lector()->consultoriosEn($this->medico, $this->sede, (bool) $this->verInactivos),
            'inactivos'    => $this->lector()->consultorios($this->medico)
                ->where('medical_center_id', $this->sede->id)->where('activo', false)->count(),
            'modalidades'  => Office::modalidades(),
            'seleccionado' => $seleccionado,
        ]);
    }

    public function abrirAgregar()
    {
        $this->limpiar();
        $this->modal = 'form';
    }

    public function abrirEditar(int $id)
    {
        $consultorio = $this->consultorio($id);

        $this->limpiar();
        $this->consultorioId = $consultorio->id;
        $this->office_number = (string) $consultorio->office_number;
        $this->phone = (string) $consultorio->phone;
        $this->modalidad = $consultorio->modalidad ?: Office::MODALIDAD_ORDEN;
        $this->duracion_cita = $consultorio->duracion_cita === null ? '' : (string) $consultorio->duracion_cita;
        $this->modal = 'form';
    }

    public function guardar(GuardarConsultorio $accion)
    {
        $tenant = $this->tenantOFallar();
        $existente = $this->consultorioId ? $this->consultorio((int) $this->consultorioId) : null;

        // Las mismas reglas que `/clinica/consultorios` (`ConsultorioController::guardar`).
        $this->validate([
            'office_number' => ['required', 'string', 'max:50'],
            'phone'         => ['nullable', 'string', 'max:50'],
            'modalidad'     => ['required', 'in:' . implode(',', array_keys(Office::modalidades()))],
            'duracion_cita' => ['nullable', 'integer', 'min:5', 'max:480'],
        ], [
            'office_number.required' => 'Pon el número o el nombre del consultorio.',
            'duracion_cita.min'      => 'La duración tiene que ser de al menos 5 minutos.',
        ]);

        // `GuardarConsultorio` sólo acepta sedes activas (404 si no): se dice antes, con palabras.
        if (! $this->sede->fresh()->activo) {
            $this->addError('office_number', 'La sede ' . $this->sede->name . ' está desactivada: hay que activarla en '
                . '/clinica/sedes antes de agregarle o editarle un consultorio.');

            return;
        }

        try {
            $accion->ejecutar([
                'medical_center_id' => $this->sede->id,
                'office_number'     => $this->office_number,
                'phone'             => $this->phone,
                'modalidad'         => $this->modalidad,
                'duracion_cita'     => $this->duracion_cita === '' || $this->duracion_cita === null ? null : (int) $this->duracion_cita,
                // La Action activa por defecto: editar no reactiva un consultorio dado de baja.
                'activo'            => $existente ? (bool) $existente->activo : true,
            ],
                // Editar conserva al doctor que lo creó (en un centro, el consultorio es del centro pero lo
                // pudo cargar uno de sus doctores); el tenant siempre es el del médico de la ruta.
                $existente && $existente->medico_id ? (int) $existente->medico_id : $this->medico->id,
                $tenant,
                $existente
            );
        } catch (ValidationException $e) {
            $this->mostrarErrores($e);

            return;
        }

        session()->flash('message', $existente
            ? 'Consultorio ' . trim((string) $this->office_number) . ' actualizado.'
            : 'Consultorio ' . trim((string) $this->office_number) . ' agregado en ' . $this->sede->name . '. Sigue con sus horarios.');
        $this->cerrarModal();
    }

    public function abrirQuitar(int $id)
    {
        $consultorio = $this->consultorio($id);

        $this->limpiar();
        $this->consultorioId = $consultorio->id;
        $this->modal = 'quitar';
    }

    public function confirmarQuitar()
    {
        $consultorio = $this->consultorio((int) $this->consultorioId);
        $consultorio->update(['activo' => false]);

        session()->flash('message', "Consultorio {$consultorio->office_number} dado de baja: deja de ofrecerse al agendar. "
            . 'Sus citas y sus horarios quedan como estaban.');
        $this->cerrarModal();
    }

    public function reactivar(int $id)
    {
        $consultorio = $this->consultorio($id);
        $consultorio->update(['activo' => true]);

        session()->flash('message', "Consultorio {$consultorio->office_number} reactivado.");
    }

    public function cerrarModal()
    {
        $this->limpiar();
        $this->modal = null;
    }

    /** Un consultorio del médico en esta sede; de otro médico o de otra sede, 404. */
    private function consultorio(int $id): Office
    {
        return $this->lector()->consultorio($this->medico, $this->sede, $id);
    }

    private function limpiar(): void
    {
        $this->resetErrorBag();
        $this->consultorioId = null;
        $this->office_number = '';
        $this->phone = '';
        $this->modalidad = Office::MODALIDAD_ORDEN;
        $this->duracion_cita = '';
    }
}
