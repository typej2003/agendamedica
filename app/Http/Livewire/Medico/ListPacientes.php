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
        $this->fnacimiento = $paciente->fnacimiento ? $paciente->fnacimiento->format('Y-m-d') : '';
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

            if ($historia) {
                $this->numhistoria = $historia->numhistoria;
                $this->medical_center_id = $historia->medical_center_id;
            } else {
                $pivot = $medico->pacientes()->where('paciente_id', $id)->first();
                $this->numhistoria = $pivot ? $pivot->pivot->numhistoria : '';
                $this->medical_center_id = '';
            }
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

            if ($this->numhistoria || $centroId) {
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
            }

            $medico->pacientes()->syncWithoutDetaching([
                $paciente->id => ['numhistoria' => $this->numhistoria]
            ]);
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

        $tieneHistoriasMedico = Historia::where('medico_id', $medico->id)
            ->where('paciente_id', $id)
            ->exists();

        $tieneConsultasMedico = Consulta::where('medico_id', $medico->id)
            ->where('paciente_id', $id)
            ->exists();

        if ($tieneHistoriasMedico || $tieneConsultasMedico) {
            $this->dispatchBrowserEvent('swal-warning', [
                'message' => 'No se puede eliminar el paciente porque posee Historias o Consultas asociadas a su cuenta médica.'
            ]);
            return;
        }

        $medico->pacientes()->detach($id);

        $paciente = Paciente::find($id);

        if ($paciente) {
            $asociacionesRestantesMedico = $paciente->medicos()->exists();
            $historiasTotales = $paciente->historias()->exists();
            $consultasTotales = $paciente->consultas()->exists();

            if (!$asociacionesRestantesMedico && !$historiasTotales && !$consultasTotales) {
                $paciente->delete();
                $this->dispatchBrowserEvent('swal-success', [
                    'message' => 'El paciente fue eliminado definitivamente de la base de datos.'
                ]);
            } else {
                $this->dispatchBrowserEvent('swal-success', [
                    'message' => 'El paciente fue desvinculado de su lista médica correctamente.'
                ]);
            }
        }
    }

    public function render()
    {
        $medico = Medico::where('user_id', Auth::id())->first() ?? Medico::first();
        $centrosSalud = collect();

        if ($medico) {
            // Obtener centros médicos asociados al médico desde la tabla historias
            $centroIds = Historia::where('medico_id', $medico->id)
                ->whereNotNull('medical_center_id')
                ->distinct()
                ->pluck('medical_center_id');

            $centrosSalud = MedicalCenter::whereIn('id', $centroIds)->get();

            // Consulta base: Incluye pacientes asociados en pivot O pacientes con historia clínica
            $query = Paciente::where(function ($q) use ($medico) {
                $q->whereHas('medicos', function ($mQuery) use ($medico) {
                    $mQuery->where('medicos.id', $medico->id);
                })
                ->orWhereHas('historias', function ($hQuery) use ($medico) {
                    $hQuery->where('medico_id', $medico->id);
                });
            });

            // Aplicar filtro de centro médico si está seleccionado
            if (!empty($this->medical_center_id_filtro)) {
                $query->whereHas('historias', function ($q) use ($medico) {
                    $q->where('medico_id', $medico->id)
                      ->where('medical_center_id', $this->medical_center_id_filtro);
                });
            }

            // Buscador por nombre, apellido o cédula
            if (!empty(trim($this->search))) {
                $term = '%' . trim($this->search) . '%';
                $query->where(function ($q) use ($term) {
                    $q->where('pacientes.nombres', 'like', $term)
                      ->orWhere('pacientes.apellidos', 'like', $term)
                      ->orWhere('pacientes.cedula', 'like', $term);
                });
            }

            // Eager loading para optimizar rendimiento N+1
            $pacientes = $query->with([
                'historias' => function ($q) use ($medico) {
                    $q->where('medico_id', $medico->id)->with('medicalCenter');
                },
                'medicos' => function ($q) use ($medico) {
                    $q->where('medicos.id', $medico->id);
                }
            ])
            ->orderBy('pacientes.id', 'desc')
            ->paginate(15);
            
            // Mapeo dinámico de datos de visualización
            foreach ($pacientes as $paciente) {
                // Buscar historia coincidente
                $historia = $paciente->historias->first(function ($h) {
                    return empty($this->medical_center_id_filtro) || $h->medical_center_id == $this->medical_center_id_filtro;
                });

                // Si existe historia y tiene un centro médico asignado, se muestra el centro médico y el numhistoria
                if ($historia && $historia->medical_center_id && $historia->medicalCenter) {
                    $paciente->num_historia_actual = $historia->numhistoria ?? 'S/H';
                    $paciente->centro_medico_actual = $historia->medicalCenter->name;
                } 
                // Si no tiene centro médico en Historia, mostrar únicamente datos de Paciente y MedicoPaciente (Pivot)
                else {
                    $medicoPivot = $paciente->medicos->first();
                    $paciente->num_historia_actual = ($historia && $historia->numhistoria) 
                        ? $historia->numhistoria 
                        : (($medicoPivot && $medicoPivot->pivot) ? $medicoPivot->pivot->numhistoria : 'S/H');
                    $paciente->centro_medico_actual = 'N/A';
                }
            }

        } else {
            $pacientes = new LengthAwarePaginator([], 0, 15);
        }

        $allMedicalCenters = MedicalCenter::all();

        return view('livewire.medico.list-pacientes', compact('pacientes', 'centrosSalud', 'allMedicalCenters'));
    }
}