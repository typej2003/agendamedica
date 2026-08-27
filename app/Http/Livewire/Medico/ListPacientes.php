<?php

namespace App\Http\Livewire\Medico;

use Livewire\Component;
use Livewire\WithPagination;
use App\Models\Paciente;
use App\Models\Medico;
use App\Models\MedicalCenter;
use App\Models\Historia;
use Illuminate\Support\Facades\Auth;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ListPacientes extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    public $search = '';
    public $paciente_id;
    public $reg_medico_filtro = ''; // Mantendremos compatibilidad o se puede usar por medical_center_id
    public $medical_center_id_filtro = ''; 
    
    // Propiedades del formulario
    public $nac = 'V', $cedula, $nombres, $apellidos, $sexo, $telefono, $email, $direccion;
    public $numhistoria, $fnacimiento, $lnacimiento, $escolaridad, $ocupacion, $profesion;
    public $medical_center_id; // Centro médico seleccionado en el formulario

    protected $rules = [
        'nac' => 'nullable|string|max:2',
        'cedula' => 'required|string|max:20',
        'nombres' => 'required|string|max:255',
        'apellidos' => 'required|string|max:255',
        'sexo' => 'nullable|string|max:1',
        'telefono' => 'nullable|string|max:50',
        'email' => 'nullable|email|max:255',
        'direccion' => 'nullable|string',
        'numhistoria' => 'nullable|string|max:50',
        'fnacimiento' => 'nullable|date',
        'lnacimiento' => 'nullable|string|max:255',
        'escolaridad' => 'nullable|string|max:255',
        'ocupacion' => 'nullable|string|max:255',
        'profesion' => 'nullable|string|max:255',
        'medical_center_id' => 'nullable|exists:medical_centers,id',
    ];

    public function updatingSearch()
    {
        $this->resetPage();
    }

    public function updatingMedicalCenterIdFiltro()
    {
        $this->resetPage();
    }

    public function resetFields()
    {
        $this->paciente_id = null;
        $this->nac = 'V';
        $this->cedula = '';
        $this->nombres = '';
        $this->apellidos = '';
        $this->sexo = '';
        $this->telefono = '';
        $this->email = '';
        $this->direccion = '';
        $this->numhistoria = '';
        $this->fnacimiento = '';
        $this->lnacimiento = '';
        $this->escolaridad = '';
        $this->ocupacion = '';
        $this->profesion = '';
        $this->medical_center_id = '';
        $this->resetValidation();
    }

    public function create()
    {
        $this->resetFields();
        $this->dispatchBrowserEvent('open-modal-paciente');
    }

    public function edit($id)
    {
        $medico = Medico::where('user_id', Auth::id())->first() ?? Medico::first();
        $paciente = Paciente::findOrFail($id);
        
        $this->paciente_id = $paciente->id;
        $this->nac = $paciente->nac;
        $this->cedula = $paciente->cedula;
        $this->nombres = $paciente->nombres;
        $this->apellidos = $paciente->apellidos;
        $this->sexo = $paciente->sexo;
        $this->telefono = $paciente->telefono;
        $this->email = $paciente->email;
        $this->direccion = $paciente->direccion;
        $this->fnacimiento = $paciente->fnacimiento;
        $this->lnacimiento = $paciente->lnacimiento;
        $this->escolaridad = $paciente->escolaridad;
        $this->ocupacion = $paciente->ocupacion;
        $this->profesion = $paciente->profesion;

        if ($medico) {
            $historiaQuery = Historia::where('medico_id', $medico->id)
                ->where('paciente_id', $id);

            if (!empty($this->medical_center_id_filtro)) {
                $historiaQuery->where('medical_center_id', $this->medical_center_id_filtro);
            }

            $historia = $historiaQuery->first();
            $this->numhistoria = $historia ? $historia->numhistoria : '';
            $this->medical_center_id = $historia ? $historia->medical_center_id : '';
        }

        $this->dispatchBrowserEvent('open-modal-paciente');
    }

    public function save()
    {
        $rules = $this->rules;
        $rules['cedula'] = 'required|string|max:20|unique:pacientes,cedula,' . $this->paciente_id;

        $this->validate($rules);

        $paciente = Paciente::updateOrCreate(
            ['id' => $this->paciente_id],
            [
                'nac' => $this->nac,
                'cedula' => $this->cedula,
                'nombres' => $this->nombres,
                'apellidos' => $this->apellidos,
                'sexo' => $this->sexo,
                'telefono' => $this->telefono,
                'email' => $this->email,
                'direccion' => $this->direccion,
                'fnacimiento' => $this->fnacimiento,
                'lnacimiento' => $this->lnacimiento,
                'escolaridad' => $this->escolaridad,
                'ocupacion' => $this->ocupacion,
                'profesion' => $this->profesion,
            ]
        );

        $medico = Medico::where('user_id', Auth::id())->first() ?? Medico::first();
        
        if ($medico) {
            $centroId = !empty($this->medical_center_id) ? $this->medical_center_id : null;

            // Sincronizar en la tabla Historias
            Historia::updateOrCreate(
                [
                    'medico_id' => $medico->id,
                    'paciente_id' => $paciente->id,
                    'medical_center_id' => $centroId,
                ],
                [
                    'numhistoria' => $this->numhistoria,
                ]
            );

            // Mantener compatibilidad con tabla pivote clásica si se usa en paralelo
            DB::table('medico_pacientes')->updateOrInsert(
                [
                    'medico_id' => $medico->id,
                    'paciente_id' => $paciente->id,
                ],
                [
                    'numhistoria' => $this->numhistoria,
                    'updated_at' => now(),
                    'created_at' => now()
                ]
            );
        }

        $this->dispatchBrowserEvent('close-modal-paciente');
        session()->flash('message', $this->paciente_id ? 'Paciente actualizado correctamente.' : 'Paciente registrado correctamente.');
        $this->resetFields();
    }

    public function delete($id)
    {
        $medico = Medico::where('user_id', Auth::id())->first() ?? Medico::first();
        if ($medico) {
            $query = Historia::where('medico_id', $medico->id)
                ->where('paciente_id', $id);

            if (!empty($this->medical_center_id_filtro)) {
                $query->where('medical_center_id', $this->medical_center_id_filtro);
            }

            $query->delete();
        }
        session()->flash('message', 'Paciente removido del centro médico correctamente.');
    }

    public function render()
    {
        $medico = Medico::where('user_id', Auth::id())->first() ?? Medico::first();
        $centrosSalud = collect();

        if ($medico) {
            // Obtener los centros médicos asociados al médico a través de sus historias o relaciones
            $centroIds = Historia::where('medico_id', $medico->id)
                ->whereNotNull('medical_center_id')
                ->distinct()
                ->pluck('medical_center_id');

            $centrosSalud = MedicalCenter::whereIn('id', $centroIds)->get();

            $query = $medico->pacientes();

            // Filtrar por el centro médico seleccionado
            if (!empty($this->medical_center_id_filtro)) {
                $query->whereHas('historias', function($q) use ($medico) {
                    $q->where('medico_id', $medico->id)
                      ->where('medical_center_id', $this->medical_center_id_filtro);
                });
            }

            if (!empty(trim($this->search))) {
                $term = '%' . trim($this->search) . '%';
                $query->where(function ($q) use ($term) {
                    $q->where('pacientes.nombres', 'like', $term)
                      ->orWhere('pacientes.apellidos', 'like', $term)
                      ->orWhere('pacientes.cedula', 'like', $term);
                });
            }

            $pacientes = $query->orderBy('pacientes.id', 'desc')->paginate(15);
            
            // Añadir información de la historia correspondiente al centro actual para cada paciente
            foreach ($pacientes as $paciente) {
                $historiaQuery = Historia::where('medico_id', $medico->id)
                    ->where('paciente_id', $paciente->id);
                if (!empty($this->medical_center_id_filtro)) {
                    $historiaQuery->where('medical_center_id', $this->medical_center_id_filtro);
                }
                $h = $historiaQuery->first();
                $paciente->num_historia_actual = $h ? $h->numhistoria : 'S/H';
                
                $centroAsociado = $h && $h->medical_center_id ? MedicalCenter::find($h->medical_center_id) : null;
                $paciente->centro_medico_actual = $centroAsociado ? $centroAsociado->name : 'N/A';
            }

        } else {
            $pacientes = new LengthAwarePaginator([], 0, 15);
        }

        // Listado completo de centros médicos disponibles para asignar en el modal
        $allMedicalCenters = MedicalCenter::all();

        return view('livewire.medico.list-pacientes', compact('pacientes', 'centrosSalud', 'allMedicalCenters'));
    }
}