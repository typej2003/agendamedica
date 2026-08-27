<?php

namespace App\Http\Livewire\Admin;

use Livewire\Component;
use Livewire\WithPagination;
use App\Models\Historia;
use App\Models\MedicalCenter;
use App\Models\Paciente;
use App\Models\Medico;
use DB;

class ListHistorias extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    public $search = '';

    // Propiedades del formulario (Crear / Editar Historia)
    public $historia_id;
    public $reg_medico;
    public $numhistoria;
    public $medical_center_id;
    public $paciente_id;
    public $medico_id;

    // Campos de búsqueda para el autocompletado en el Modal
    public $searchCentro = '';
    public $searchPaciente = '';
    public $searchMedico = '';

    // Selección actual visible en inputs de autocompletado
    public $selectedCentroName = '';
    public $selectedPacienteName = '';
    public $selectedMedicoName = '';

    public $isEditMode = false;

    // Propiedades para modales de detalle
    public $detailTitle = '';
    public $detailType = ''; // 'historias_list', 'pacientes_list', 'medico'
    public $historiasDetalle = [];
    public $pacientesDetalle = [];
    public $selectedMedico = null;

    protected $rules = [
        'reg_medico'        => 'nullable|string|max:100',
        'numhistoria'       => 'nullable|string|max:100',
        'medical_center_id' => 'nullable|exists:medical_centers,id',
        'paciente_id'       => 'nullable|exists:pacientes,id',
        'medico_id'         => 'nullable|exists:medicos,id',
    ];

    public function updatingSearch()
    {
        $this->resetPage();
    }

    public function resetForm()
    {
        $this->reset([
            'historia_id',
            'reg_medico',
            'numhistoria',
            'medical_center_id',
            'paciente_id',
            'medico_id',
            'searchCentro',
            'searchPaciente',
            'searchMedico',
            'selectedCentroName',
            'selectedPacienteName',
            'selectedMedicoName',
            'isEditMode'
        ]);
        $this->resetValidation();
    }

    public function createHistoria()
    {
        $this->resetForm();
        $this->dispatchBrowserEvent('open-modal-edit-centro');
    }

    public function editCentro($id)
    {
        $this->resetForm();
        $this->isEditMode = true;

        $historia = Historia::with(['medicalCenter', 'paciente', 'medico'])->findOrFail($id);

        $this->historia_id       = $historia->id;
        $this->reg_medico        = $historia->getAttribute('reg-medico');
        $this->numhistoria       = $historia->numhistoria;
        $this->medical_center_id = $historia->medical_center_id;
        $this->paciente_id       = $historia->paciente_id;
        $this->medico_id         = $historia->medico_id;

        if ($historia->medicalCenter) {
            $this->selectedCentroName = $historia->medicalCenter->name;
        }
        if ($historia->paciente) {
            $this->selectedPacienteName = $historia->paciente->nombres . ' ' . $historia->paciente->apellidos . ' (C.I: ' . $historia->paciente->cedula . ')';
        }
        if ($historia->medico) {
            $this->selectedMedicoName = trim($historia->medico->name . ' ' . ($historia->medico->lastname ?? ''));
        }

        $this->dispatchBrowserEvent('open-modal-edit-centro');
    }

    public function selectCentro($id, $name)
    {
        $this->medical_center_id = $id;
        $this->selectedCentroName = $name;
        $this->searchCentro = '';
    }

    public function selectPaciente($id, $name)
    {
        $this->paciente_id = $id;
        $this->selectedPacienteName = $name;
        $this->searchPaciente = '';
    }

    public function selectMedico($id, $name)
    {
        $this->medico_id = $id;
        $this->selectedMedicoName = $name;
        $this->searchMedico = '';
    }

    public function clearCentro()
    {
        $this->medical_center_id = null;
        $this->selectedCentroName = '';
        $this->searchCentro = '';
    }

    public function clearPaciente()
    {
        $this->paciente_id = null;
        $this->selectedPacienteName = '';
        $this->searchPaciente = '';
    }

    public function clearMedico()
    {
        $this->medico_id = null;
        $this->selectedMedicoName = '';
        $this->searchMedico = '';
    }

    public function saveHistoria()
    {
        $this->validate();

        $data = [
            'reg-medico'        => $this->reg_medico ?: null,
            'numhistoria'       => $this->numhistoria ?: null,
            'medical_center_id' => $this->medical_center_id ?: null,
            'paciente_id'       => $this->paciente_id ?: null,
            'medico_id'         => $this->medico_id ?: null,
        ];

        if ($this->isEditMode && $this->historia_id) {
            $historia = Historia::findOrFail($this->historia_id);
            $historia->update($data);

            session()->flash('message', 'Historia médica actualizada correctamente.');
        } else {
            Historia::create($data);

            session()->flash('message', 'Historia médica creada correctamente.');
        }

        $this->dispatchBrowserEvent('close-modal-edit-centro');
        $this->resetForm();
    }

    public function deleteHistoria($id)
    {
        $historia = Historia::findOrFail($id);
        $historia->delete();

        session()->flash('message', 'Historia médica eliminada correctamente.');
    }

    // Modal para ver Historias asociadas al reg-medico
    public function showByRegMedico($regMedico)
    {
        $this->detailType = 'historias_list';
        $this->detailTitle = "Historias del Registro Médico: {$regMedico}";
        $this->historiasDetalle = Historia::with(['paciente', 'medico', 'medicalCenter'])
            ->where('reg-medico', $regMedico)
            ->get();

        $this->dispatchBrowserEvent('open-modal-detail');
    }

    // Modal para ver Historias asociadas a un Centro Médico
    public function showByCentro($centroId)
    {
        $centro = MedicalCenter::find($centroId);
        $nombreCentro = $centro ? $centro->name : 'Sin Centro Asignado';

        $this->detailType = 'historias_list';
        $this->detailTitle = "Historias en el Centro Médico: {$nombreCentro}";
        $this->historiasDetalle = Historia::with(['paciente', 'medico', 'medicalCenter'])
            ->where('medical_center_id', $centroId)
            ->get();

        $this->dispatchBrowserEvent('open-modal-detail');
    }

    // Modal para ver Pacientes asociados a ese reg-medico
    public function showPacientesByRegMedico($regMedico)
    {
        $this->detailType = 'pacientes_list';
        $this->detailTitle = "Pacientes del Registro Médico: {$regMedico}";

        $pacienteIds = Historia::where('reg-medico', $regMedico)
            ->whereNotNull('paciente_id')
            ->pluck('paciente_id')
            ->unique();

        $this->pacientesDetalle = Paciente::whereIn('id', $pacienteIds)->get();

        $this->dispatchBrowserEvent('open-modal-detail');
    }

    // Modal para ver Detalle del Médico
    public function showMedicoDetail($medicoId)
    {
        $this->detailType = 'medico';
        $this->selectedMedico = Medico::with('specialties')->find($medicoId);
        $this->detailTitle = "Detalle del Médico";

        $this->dispatchBrowserEvent('open-modal-detail');
    }

    public function render()
    {
        $searchTerm = trim($this->search);
        $isSearching = !empty($searchTerm);

        $query = Historia::with(['medicalCenter', 'paciente', 'medico']);

        if ($isSearching) {
            // Si hay búsqueda activa: Mostrar TODOS los registros detallados uno por uno
            $term = '%' . $searchTerm . '%';
            $query->where(function ($q) use ($term) {
                $q->where('reg-medico', 'like', $term)
                  ->orWhere('numhistoria', 'like', $term)
                  ->orWhereHas('paciente', function ($qp) use ($term) {
                      $qp->where('nombres', 'like', $term)
                         ->orWhere('apellidos', 'like', $term)
                         ->orWhere('cedula', 'like', $term)
                         ->orWhere(DB::raw("CONCAT(nombres, ' ', apellidos)"), 'like', $term);
                  })
                  ->orWhereHas('medico', function ($qm) use ($term) {
                      $qm->where('name', 'like', $term)
                         ->orWhere('lastname', 'like', $term)
                         ->orWhere('license_number', 'like', $term)
                         ->orWhere(DB::raw("CONCAT(name, ' ', lastname)"), 'like', $term);
                  })
                  ->orWhereHas('medicalCenter', function ($qc) use ($term) {
                      $qc->where('name', 'like', $term);
                  });
            });
        } else {
            // Si NO hay búsqueda: Agrupar por reg-medico (mostrar 1 fila principal por código)
            $query->whereIn('id', function ($q) {
                $q->selectRaw('MAX(id)')
                  ->from('historias')
                  ->groupBy('reg-medico');
            });
        }

        $historias = $query->orderBy('id', 'desc')->paginate(15);

        // Agregamos el conteo de registros por cada reg-medico
        $regMedicos = $historias->pluck('reg-medico')->filter()->unique();
        $countsByReg = Historia::whereIn('reg-medico', $regMedicos)
            ->selectRaw('`reg-medico`, COUNT(*) as total')
            ->groupBy('reg-medico')
            ->pluck('total', 'reg-medico');

        // Autocompletado del Modal
        $centrosResult = [];
        if (strlen(trim($this->searchCentro)) >= 1) {
            $centrosResult = MedicalCenter::where('name', 'like', '%' . trim($this->searchCentro) . '%')
                ->take(5)->get();
        }

        $pacientesResult = [];
        if (strlen(trim($this->searchPaciente)) >= 1) {
            $termP = '%' . trim($this->searchPaciente) . '%';
            $pacientesResult = Paciente::where('nombres', 'like', $termP)
                ->orWhere('apellidos', 'like', $termP)
                ->orWhere('cedula', 'like', $termP)
                ->orWhere(DB::raw("CONCAT(nombres, ' ', apellidos)"), 'like', $termP)
                ->take(5)->get();
        }

        $medicosResult = [];
        if (strlen(trim($this->searchMedico)) >= 1) {
            $termM = '%' . trim($this->searchMedico) . '%';
            $medicosResult = Medico::where('name', 'like', $termM)
                ->orWhere('lastname', 'like', $termM)
                ->orWhere('license_number', 'like', $termM)
                ->orWhere(DB::raw("CONCAT(name, ' ', lastname)"), 'like', $termM)
                ->take(5)->get();
        }

        return view('livewire.admin.list-historias', compact(
            'historias',
            'countsByReg',
            'centrosResult',
            'pacientesResult',
            'medicosResult',
            'isSearching'
        ));
    }
}