<?php

namespace App\Http\Livewire\Medico;

use Livewire\Component;
use Livewire\WithPagination;
use App\Models\MedicoMedicalCenter;
use App\Models\Medico;
use App\Models\MedicalCenter;
use App\Models\MedicoRegistro;

class ListMedicoCenterMedical extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    // Propiedades del formulario / modal
    public $relation_id;
    public $medico_id = '';
    public $medical_center_id = '';
    public $reg_medico = '';

    // Autocompletar Médico en Modal
    public $searchMedicoModal = '';
    public $selectedMedicoText = '';
    public $medicosSearchResults = [];

    // Búsqueda en la Tabla principal
    public $search = '';
    public $isEdit = false;

    // Escuchadores de eventos para JavaScript (SweetAlert)
    protected $listeners = [
        'deleteRelationConfirmed' => 'deleteRelation',
    ];

    protected function rules()
    {
        return [
            'medico_id'         => 'required|exists:medicos,id',
            'medical_center_id' => 'required|exists:medical_centers,id',
            'reg_medico'         => 'nullable|string|max:100',
        ];
    }

    protected $messages = [
        'medico_id.required'         => 'Debe seleccionar un médico.',
        'medico_id.exists'           => 'El médico seleccionado no es válido.',
        'medical_center_id.required' => 'Debe seleccionar un centro médico.',
        'medical_center_id.exists'   => 'El centro médico seleccionado no es válido.',
    ];

    public function updatingSearch()
    {
        $this->resetPage();
    }

    // Lógica de búsqueda de autocompletado en el modal
    public function updatedSearchMedicoModal($value)
    {
        if (strlen(\trim($value)) >= 2) {
            $term = '%' . \trim($value) . '%';
            $this->medicosSearchResults = Medico::where(function ($query) use ($term) {
                $query->where('name', 'like', $term)
                      ->orWhere('lastname', 'like', $term)
                      ->orWhere('license_number', 'like', $term);
            })
            ->take(8)
            ->get();
        } else {
            $this->medicosSearchResults = [];
        }
    }

    public function selectMedico($id)
    {
        $medico = Medico::find($id);
        if ($medico) {
            $this->medico_id = $medico->id;
            $this->selectedMedicoText = $medico->name . ' ' . $medico->lastname . ($medico->license_number ? ' (Lic: ' . $medico->license_number . ')' : '');
            $this->searchMedicoModal = '';
            $this->medicosSearchResults = [];

            // Buscar si ya posee un reg_medico en la tabla medico_registros
            $registro = MedicoRegistro::where('medico_id', $medico->id)->first();
            if ($registro) {
                $this->reg_medico = $registro->reg_medico;
            } else {
                $this->reg_medico = '';
            }
        }
    }

    public function clearSelectedMedico()
    {
        $this->medico_id = '';
        $this->selectedMedicoText = '';
        $this->searchMedicoModal = '';
        $this->medicosSearchResults = [];
        $this->reg_medico = '';
    }

    public function render()
    {
        $searchTerm = '%' . \trim($this->search) . '%';

        // Consulta de relaciones haciendo JOIN con medico_registros para obtener reg_medico
        $relaciones = MedicoMedicalCenter::query()
            ->leftJoin('medicos', 'medico_medical_center.medico_id', '=', 'medicos.id')
            ->leftJoin('medical_centers', 'medico_medical_center.medical_center_id', '=', 'medical_centers.id')
            ->leftJoin('medico_registros', 'medico_medical_center.medico_id', '=', 'medico_registros.medico_id')
            ->where(function ($query) use ($searchTerm) {
                $query->where('medicos.name', 'like', $searchTerm)
                      ->orWhere('medicos.lastname', 'like', $searchTerm)
                      ->orWhere('medicos.license_number', 'like', $searchTerm)
                      ->orWhere('medical_centers.name', 'like', $searchTerm)
                      ->orWhere('medico_registros.reg_medico', 'like', $searchTerm);
            })
            ->select(
                'medico_medical_center.*',
                'medico_registros.reg_medico as reg_medico_val'
            )
            ->orderBy('medico_medical_center.id', 'desc')
            ->paginate(10);

        $centrosMedicos = MedicalCenter::orderBy('name', 'asc')->get();

        return view('livewire.medico.list-medico-center-medical', [
            'relaciones'     => $relaciones,
            'centrosMedicos' => $centrosMedicos,
        ])->extends('layouts.app');
    }

    public function resetFields()
    {
        $this->relation_id = null;
        $this->medico_id = '';
        $this->medical_center_id = '';
        $this->reg_medico = '';
        $this->searchMedicoModal = '';
        $this->selectedMedicoText = '';
        $this->medicosSearchResults = [];
        $this->isEdit = false;
        $this->resetValidation();
    }

    public function openModal()
    {
        $this->resetFields();
        $this->dispatchBrowserEvent('open-modal');
    }

    public function closeModal()
    {
        $this->resetFields();
        $this->dispatchBrowserEvent('close-modal');
    }

    public function store()
    {
        $this->validate();

        $exists = MedicoMedicalCenter::where('medico_id', $this->medico_id)
            ->where('medical_center_id', $this->medical_center_id)
            ->exists();

        if ($exists) {
            $this->dispatchBrowserEvent('swal:alert', [
                'type'  => 'error',
                'title' => 'Relación Duplicada',
                'text'  => 'El médico ya está asignado a este centro médico.',
            ]);
            return;
        }

        // Crear asignación de centro médico
        MedicoMedicalCenter::create([
            'medico_id'         => $this->medico_id,
            'medical_center_id' => $this->medical_center_id,
        ]);

        // Guardar o actualizar reg_medico en la tabla medico_registros si fue proporcionado
        if (!empty($this->reg_medico)) {
            MedicoRegistro::updateOrCreate(
                ['medico_id' => $this->medico_id],
                ['reg_medico' => $this->reg_medico]
            );
        }

        $this->closeModal();

        $this->dispatchBrowserEvent('swal:alert', [
            'type'  => 'success',
            'title' => '¡Éxito!',
            'text'  => 'La relación se ha guardado correctamente.',
        ]);
    }

    public function edit($id)
    {
        $this->resetFields();
        $relacion = MedicoMedicalCenter::findOrFail($id);

        $this->relation_id       = $relacion->id;
        $this->medico_id         = $relacion->medico_id;
        $this->medical_center_id = $relacion->medical_center_id;
        $this->isEdit            = true;

        $medico = Medico::find($relacion->medico_id);
        if ($medico) {
            $this->selectedMedicoText = $medico->name . ' ' . $medico->lastname . ($medico->license_number ? ' (Lic: ' . $medico->license_number . ')' : '');
        }

        $registro = MedicoRegistro::where('medico_id', $relacion->medico_id)->first();
        if ($registro) {
            $this->reg_medico = $registro->reg_medico;
        }

        $this->dispatchBrowserEvent('open-modal');
    }

    public function update()
    {
        $this->validate();

        $exists = MedicoMedicalCenter::where('medico_id', $this->medico_id)
            ->where('medical_center_id', $this->medical_center_id)
            ->where('id', '!=', $this->relation_id)
            ->exists();

        if ($exists) {
            $this->dispatchBrowserEvent('swal:alert', [
                'type'  => 'error',
                'title' => 'Relación Duplicada',
                'text'  => 'El médico ya se encuentra asignado a este centro médico.',
            ]);
            return;
        }

        $relacion = MedicoMedicalCenter::findOrFail($this->relation_id);
        $relacion->update([
            'medico_id'         => $this->medico_id,
            'medical_center_id' => $this->medical_center_id,
        ]);

        if (!empty($this->reg_medico)) {
            MedicoRegistro::updateOrCreate(
                ['medico_id' => $this->medico_id],
                ['reg_medico' => $this->reg_medico]
            );
        }

        $this->closeModal();

        $this->dispatchBrowserEvent('swal:alert', [
            'type'  => 'success',
            'title' => '¡Actualizado!',
            'text'  => 'La relación ha sido actualizada exitosamente.',
        ]);
    }

    public function confirmDelete($id)
    {
        $this->dispatchBrowserEvent('swal:confirm', [
            'id'    => $id,
            'title' => '¿Estás seguro?',
            'text'  => 'Se eliminará la relación de este médico con el centro médico.',
        ]);
    }

    public function deleteRelation($id)
    {
        MedicoMedicalCenter::destroy($id);

        $this->dispatchBrowserEvent('swal:alert', [
            'type'  => 'success',
            'title' => '¡Eliminado!',
            'text'  => 'El registro ha sido eliminado correctamente.',
        ]);
    }
}