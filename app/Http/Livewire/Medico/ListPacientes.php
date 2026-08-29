<?php

namespace App\Http\Livewire\Medico;

use Livewire\Component;
use Livewire\WithPagination;
use App\Models\Medico;
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
        
        // 1. Obtener el ID del Médico utilizando el user_id del Auth::id()
        $medico = Medico::where('user_id', Auth::id())->first();
        $medicoId = $medico ? $medico->id : null;

        // 2. Obtener el numhistoria del pivote MedicoPaciente
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

            // Obtener el registro del médico a través del ID del usuario autenticado
            $medico = Medico::where('user_id', Auth::id())->first();

            if (!$medico) {
                throw new \Exception('No se encontró el registro de médico asociado a este usuario.');
            }

            $medicoId = $medico->id;

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

                // Guardar o actualizar la relación en medico_pacientes
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

                // Crear el registro de la relación en medico_pacientes
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
            $medico = Medico::where('user_id', Auth::id())->first();
            $medicoId = $medico ? $medico->id : null;

            // Eliminar la relación pivot MedicoPaciente
            MedicoPaciente::where('paciente_id', $id)
                ->where('medico_id', $medicoId)
                ->delete();

            // Eliminar el paciente
            $paciente->delete();

            $this->dispatchBrowserEvent('swal-success', ['message' => 'Paciente eliminado correctamente.']);
        } catch (\Exception $e) {
            $this->dispatchBrowserEvent('swal-error', ['message' => 'No se pudo eliminar el paciente debido a registros asociados.']);
        }
    }

    public function render()
    {
        $centrosSalud = MedicalCenter::orderBy('name', 'asc')->get();

        // 1. Obtener el Modelo del Médico autenticado buscando por la relación user_id -> Auth::id()
        $medico = Medico::where('user_id', Auth::id())->first();

        if ($medico) {
            // 2. Con el ID del Medico encontrado ($medico->id), obtenemos la lista de Pacientes asociados mediante su relación BelongsToMany
            $pacientes = $medico->pacientes()
                // Búsqueda general en la tabla de Pacientes y en la tabla Pivote MedicoPaciente
                ->paginate(10);
        } else {
            // Devolver un Paginador vacío para mantener la compatibilidad con el renderizado de la vista
            $pacientes = Paciente::whereRaw('1 = 0')->paginate(10);
        }

        return view('livewire.medico.list-pacientes', [
            'pacientes'    => $pacientes,
            'centrosSalud' => $centrosSalud,
        ]);
    }
}