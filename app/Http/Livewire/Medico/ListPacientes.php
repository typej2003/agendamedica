<?php

namespace App\Http\Livewire\Medico;

use Livewire\Component;
use Livewire\WithPagination;
use App\Models\Paciente;
use App\Models\Medico;
use App\Models\MedicalCenter;
use App\Models\Historia;
use App\Models\Consulta;
use Illuminate\Support\Facades\Auth;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ListPacientes extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    public $search = '';
    public $paciente_id;
    public $reg_medico_filtro = ''; 
    public $medical_center_id_filtro = ''; 
    
    // Propiedades del formulario
    public $nac = 'V', $cedula, $nombres, $apellidos, $sexo, $telefono, $email, $direccion;
    public $numhistoria, $fnacimiento, $lnacimiento, $escolaridad, $ocupacion, $profesion;
    public $medical_center_id;

    protected $listeners = ['confirmDeletePaciente' => 'delete'];

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

    public function triggerDeleteConfirm($id)
    {
        $this->dispatchBrowserEvent('show-delete-confirm', ['id' => $id]);
    }

    public function delete($id)
    {
        $medico = Medico::where('user_id', Auth::id())->first() ?? Medico::first();

        if (!$medico) {
            $this->dispatchBrowserEvent('swal-error', ['message' => 'Médico no autenticado.']);
            return;
        }

        // 1. Verificar si existen registros asociados (Historia o Consulta) para EL MÉDICO ACTIVO
        $tieneHistoriasMedico = Historia::where('medico_id', $medico->id)
            ->where('paciente_id', $id)
            ->exists();

        $tieneConsultasMedico = Consulta::where('medico_id', $medico->id)
            ->where('paciente_id', $id)
            ->exists();

        // Regla: Si tiene registros asociados al médico activo, NO se puede eliminar de la tabla Paciente
        if ($tieneHistoriasMedico || $tieneConsultasMedico) {
            $this->dispatchBrowserEvent('swal-warning', [
                'message' => 'No se puede eliminar el paciente porque posee Historias o Consultas asociadas a su cuenta médica.'
            ]);
            return;
        }

        // 2. Si no tiene asociación/registros con este médico, desvincular del modelo MedicoPaciente
        DB::table('medico_pacientes')
            ->where('medico_id', $medico->id)
            ->where('paciente_id', $id)
            ->delete();

        // 3. Evaluar si el paciente sigue vinculado a algún otro médico o a registros globales
        $asociacionesRestantesMedico = DB::table('medico_pacientes')
            ->where('paciente_id', $id)
            ->exists();

        $historiasTotales = Historia::where('paciente_id', $id)->exists();
        $consultasTotales = Consulta::where('paciente_id', $id)->exists();

        // Si no existe ninguna relación con otro médico ni registros en el sistema, se elimina completamente del modelo Paciente
        if (!$asociacionesRestantesMedico && !$historiasTotales && !$consultasTotales) {
            Paciente::where('id', $id)->delete();
            $this->dispatchBrowserEvent('swal-success', [
                'message' => 'El paciente fue eliminado definitivamente de la base de datos.'
            ]);
        } else {
            $this->dispatchBrowserEvent('swal-success', [
                'message' => 'El paciente fue desvinculado de su lista médica correctamente.'
            ]);
        }
    }

    public function render()
    {
        $medico = Medico::where('user_id', Auth::id())->first() ?? Medico::first();
        $centrosSalud = collect();

        if ($medico) {
            $centroIds = Historia::where('medico_id', $medico->id)
                ->whereNotNull('medical_center_id')
                ->distinct()
                ->pluck('medical_center_id');

            $centrosSalud = MedicalCenter::whereIn('id', $centroIds)->get();

            // Filtrar pacientes considerando el centro médico mediante la tabla historias
            $query = Paciente::whereHas('historias', function ($q) use ($medico) {
                $q->where('medico_id', $medico->id);
                
                if (!empty($this->medical_center_id_filtro)) {
                    $q->where('medical_center_id', $this->medical_center_id_filtro);
                }
            });

            if (!empty(trim($this->search))) {
                $term = '%' . trim($this->search) . '%';
                $query->where(function ($q) use ($term) {
                    $q->where('pacientes.nombres', 'like', $term)
                      ->orWhere('pacientes.apellidos', 'like', $term)
                      ->orWhere('pacientes.cedula', 'like', $term);
                });
            }

            $pacientes = $query->orderBy('pacientes.id', 'desc')->paginate(15);
            
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

        $allMedicalCenters = MedicalCenter::all();

        return view('livewire.medico.list-pacientes', compact('pacientes', 'centrosSalud', 'allMedicalCenters'));
    }
}