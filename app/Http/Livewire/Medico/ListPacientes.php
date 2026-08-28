<?php

namespace App\Http\Livewire\Medico;

use Livewire\Component;
use Livewire\WithPagination;
use App\Models\Paciente;
use App\Models\MedicalCenter;
use App\Models\MedicoPaciente;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;

class ListPacientes extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    // Filtros de búsqueda
    public $search = '';
    public $medical_center_id_filtro = '';

    // Campos del Formulario (Modal Paciente)
    public $paciente_id;
    public $numhistoria;
    public $nac = 'V';
    public $cedula;
    public $nombres;
    public $apellidos;
    public $sexo;
    public $fnacimiento;
    public $lnacimiento;
    public $telefono;
    public $email;
    public $password;
    public $escolaridad;
    public $ocupacion;
    public $profesion;
    public $direccion;

    // Eventos escuchados desde JavaScript / SweetAlert2
    protected $listeners = [
        'confirmDeletePaciente' => 'delete'
    ];

    public function updatingSearch()
    {
        $this->resetPage();
    }

    public function updatingMedicalCenterIdFiltro()
    {
        $this->resetPage();
    }

    protected function rules()
    {
        return [
            'numhistoria'  => 'required|string|max:50',
            'nac'          => 'required|in:V,E,P',
            'cedula'       => 'required|string|max:20|unique:pacientes,cedula,' . $this->paciente_id,
            'nombres'      => 'required|string|max:100',
            'apellidos'    => 'required|string|max:100',
            'sexo'         => 'nullable|in:M,F',
            'fnacimiento'  => 'nullable|date',
            'lnacimiento'  => 'nullable|string|max:150',
            'telefono'     => 'nullable|string|max:20',
            'email'        => 'nullable|email|max:150|unique:pacientes,email,' . $this->paciente_id,
            'password'     => 'nullable|string|min:6',
            'escolaridad'  => 'nullable|string|max:100',
            'ocupacion'    => 'nullable|string|max:100',
            'profesion'    => 'nullable|string|max:100',
            'direccion'    => 'nullable|string|max:255',
        ];
    }

    protected $messages = [
        'numhistoria.required' => 'El número de historia es obligatorio.',
        'cedula.required'      => 'La cédula es obligatoria.',
        'cedula.unique'        => 'Esta cédula ya está registrada.',
        'nombres.required'     => 'El campo nombres es obligatorio.',
        'apellidos.required'   => 'El campo apellidos es obligatorio.',
        'email.email'          => 'Ingrese un correo electrónico válido.',
        'email.unique'         => 'Este correo ya pertenece a otro paciente.',
        'password.min'         => 'La contraseña debe tener al menos 6 caracteres.',
    ];

    public function resetInputFields()
    {
        $this->paciente_id = null;
        $this->numhistoria = '';
        $this->nac = 'V';
        $this->cedula = '';
        $this->nombres = '';
        $this->apellidos = '';
        $this->sexo = '';
        $this->fnacimiento = null;
        $this->lnacimiento = '';
        $this->telefono = '';
        $this->email = '';
        $this->password = '';
        $this->escolaridad = '';
        $this->ocupacion = '';
        $this->profesion = '';
        $this->direccion = '';
        $this->resetValidation();
    }

    public function create()
    {
        $this->resetInputFields();
        $this->dispatchBrowserEvent('open-modal-paciente');
    }

    public function edit($id)
    {
        $paciente = Paciente::findOrFail($id);
        $medicoId = Auth::user()->medico->id ?? Auth::id();

        // Obtener el registro pivot correspondiente a este médico y paciente
        $relacionMedico = MedicoPaciente::where('paciente_id', $paciente->id)
            ->where('medico_id', $medicoId)
            ->first();

        $this->paciente_id = $paciente->id;
        $this->numhistoria  = $relacionMedico->numhistoria ?? '';
        $this->nac          = $paciente->nac ?? 'V';
        $this->cedula       = $paciente->cedula;
        $this->nombres      = $paciente->nombres;
        $this->apellidos    = $paciente->apellidos;
        $this->sexo         = $paciente->sexo;
        $this->fnacimiento  = $paciente->fnacimiento ? $paciente->fnacimiento->format('Y-m-d') : null;
        $this->lnacimiento  = $paciente->lnacimiento;
        $this->telefono     = $paciente->telefono;
        $this->email        = $paciente->email;
        $this->password     = '';
        $this->escolaridad  = $paciente->escolaridad;
        $this->ocupacion    = $paciente->ocupacion;
        $this->profesion    = $paciente->profesion;
        $this->direccion    = $paciente->direccion;

        $this->dispatchBrowserEvent('open-modal-paciente');
    }

    public function save()
    {
        $this->validate();

        try {
            DB::beginTransaction();

            $medicoId = Auth::user()->medico->id ?? Auth::id();

            $data = [
                'nac'         => $this->nac,
                'cedula'      => $this->cedula,
                'nombres'     => $this->nombres,
                'apellidos'   => $this->apellidos,
                'sexo'        => $this->sexo ?: null,
                'fnacimiento' => $this->fnacimiento ?: null,
                'lnacimiento' => $this->lnacimiento,
                'telefono'    => $this->telefono,
                'email'       => $this->email ?: null,
                'escolaridad' => $this->escolaridad,
                'ocupacion'   => $this->ocupacion,
                'profesion'   => $this->profesion,
                'direccion'   => $this->direccion,
            ];

            if (!empty($this->password)) {
                $data['password'] = Hash::make($this->password);
            }

            if ($this->paciente_id) {
                $paciente = Paciente::findOrFail($this->paciente_id);
                $paciente->update($data);

                // Actualizar la relación pivot MedicoPaciente
                MedicoPaciente::updateOrCreate(
                    [
                        'medico_id'   => $medicoId,
                        'paciente_id' => $paciente->id,
                    ],
                    [
                        'numhistoria' => $this->numhistoria,
                    ]
                );

                $mensaje = 'Paciente actualizado correctamente.';
            } else {
                $paciente = Paciente::create($data);

                // Crear la relación pivot MedicoPaciente
                MedicoPaciente::create([
                    'medico_id'   => $medicoId,
                    'paciente_id' => $paciente->id,
                    'numhistoria' => $this->numhistoria,
                ]);

                $mensaje = 'Paciente registrado correctamente.';
            }

            DB::commit();

            $this->dispatchBrowserEvent('close-modal-paciente');
            $this->dispatchBrowserEvent('swal-success', ['message' => $mensaje]);
            $this->resetInputFields();

        } catch (\Exception $e) {
            DB::rollBack();
            $this->dispatchBrowserEvent('swal-error', ['message' => 'Ocurrió un error al procesar la solicitud: ' . $e->getMessage()]);
        }
    }

    public function triggerDeleteConfirm($id)
    {
        $this->dispatchBrowserEvent('show-delete-confirm', ['id' => $id]);
    }

    public function delete($id)
    {
        try {
            $paciente = Paciente::findOrFail($id);
            $medicoId = Auth::user()->medico->id ?? Auth::id();

            // Desvincular de la relación MedicoPaciente
            MedicoPaciente::where('paciente_id', $id)
                ->where('medico_id', $medicoId)
                ->delete();

            // Eliminar el registro de paciente si aplica
            $paciente->delete();

            $this->dispatchBrowserEvent('swal-success', ['message' => 'Paciente eliminado correctamente.']);
        } catch (\Exception $e) {
            $this->dispatchBrowserEvent('swal-error', ['message' => 'No se pudo eliminar el paciente debido a registros asociados.']);
        }
    }

    public function render()
    {
        $centrosSalud = MedicalCenter::orderBy('name', 'asc')->get();
        $medicoId = Auth::user()->medico->id ?? Auth::id();

        $pacientes = Paciente::query()
            ->select('pacientes.*') // Asegura que se seleccionen todas las columnas del paciente
            ->with(['historias.medicalCenter', 'medicos'])
            // Subselect para obtener el numhistoria asignado por este médico en la pivot
            ->addSelect([
                'numhistoria' => MedicoPaciente::select('numhistoria')
                    ->whereColumn('medico_pacientes.paciente_id', 'pacientes.id')
                    ->where('medico_pacientes.medico_id', $medicoId)
                    ->limit(1)
            ])
            // Filtrar solo pacientes pertenecientes al Médico actual
            ->whereHas('medicos', function ($q) use ($medicoId) {
                $q->where('medico_id', $medicoId);
            })
            // Filtrar por Centro Médico mediante la relación Historias -> MedicalCenter
            ->when($this->medical_center_id_filtro, function ($query) {
                $query->whereHas('historias', function ($q) {
                    $q->where('medical_center_id', $this->medical_center_id_filtro);
                });
            })
            // Búsqueda por Cédula, Nombres, Apellidos o N° de Historia
            ->when($this->search, function ($query) use ($medicoId) {
                $query->where(function ($q) use ($medicoId) {
                    $q->where('cedula', 'like', '%' . $this->search . '%')
                      ->orWhere('nombres', 'like', '%' . $this->search . '%')
                      ->orWhere('apellidos', 'like', '%' . $this->search . '%')
                      ->orWhereHas('medicos', function ($m) use ($medicoId) {
                          $m->where('medico_id', $medicoId)
                            ->where('numhistoria', 'like', '%' . $this->search . '%');
                      });
                });
            })
            ->latest()
            ->paginate(10);

        return view('livewire.medico.list-pacientes', [
            'pacientes'    => $pacientes,
            'centrosSalud' => $centrosSalud,
        ]);
    }
}