<?php

namespace App\Http\Livewire\Medico;

use Livewire\Component;
use Livewire\WithPagination;
use App\Models\Paciente;
use App\Models\MedicalCenter;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

class ListPacientes extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    // Filtros de búsqueda
    public $search = '';
    public $medical_center_id_filtro = '';

    // Campos del Formulario (Modal Paciente)
    public $paciente_id;
    public $medical_center_id;
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
            'medical_center_id' => 'nullable|exists:medical_centers,id',
            'numhistoria'        => 'nullable|string|max:50',
            'nac'                => 'required|in:V,E,P',
            'cedula'             => 'required|string|max:20|unique:pacientes,cedula,' . $this->paciente_id,
            'nombres'            => 'required|string|max:100',
            'apellidos'          => 'required|string|max:100',
            'sexo'               => 'nullable|in:M,F',
            'fnacimiento'        => 'nullable|date',
            'lnacimiento'        => 'nullable|string|max:150',
            'telefono'           => 'nullable|string|max:20',
            'email'              => 'nullable|email|max:150|unique:pacientes,email,' . $this->paciente_id,
            'password'           => 'nullable|string|min:6',
            'escolaridad'        => 'nullable|string|max:100',
            'ocupacion'          => 'nullable|string|max:100',
            'profesion'          => 'nullable|string|max:100',
            'direccion'          => 'nullable|string|max:255',
        ];
    }

    protected $messages = [
        'cedula.required'   => 'La cédula es obligatoria.',
        'cedula.unique'     => 'Esta cédula ya está registrada.',
        'nombres.required'  => 'El campo nombres es obligatorio.',
        'apellidos.required'=> 'El campo apellidos es obligatorio.',
        'email.email'       => 'Ingrese un correo electrónico válido.',
        'email.unique'      => 'Este correo ya pertenece a otro paciente.',
        'password.min'      => 'La contraseña debe tener al menos 6 caracteres.',
    ];

    public function resetInputFields()
    {
        $this->paciente_id = null;
        $this->medical_center_id = null;
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

        $this->paciente_id       = $paciente->id;
        $this->medical_center_id = $paciente->medical_center_id ?? null;
        $this->numhistoria       = $paciente->numhistoria ?? $paciente->num_historia_actual ?? '';
        $this->nac                = $paciente->nac ?? 'V';
        $this->cedula             = $paciente->cedula;
        $this->nombres            = $paciente->nombres;
        $this->apellidos          = $paciente->apellidos;
        $this->sexo               = $paciente->sexo;
        $this->fnacimiento        = $paciente->fnacimiento ? $paciente->fnacimiento->format('Y-m-d') : null;
        $this->lnacimiento        = $paciente->lnacimiento;
        $this->telefono           = $paciente->telefono;
        $this->email              = $paciente->email;
        $this->password           = ''; // Se deja vacío al editar para no reemplazarlo salvo que se ingrese uno nuevo
        $this->escolaridad        = $paciente->escolaridad;
        $this->ocupacion          = $paciente->ocupacion;
        $this->profesion          = $paciente->profesion;
        $this->direccion          = $paciente->direccion;

        $this->dispatchBrowserEvent('open-modal-paciente');
    }

    public function save()
    {
        $this->validate();

        try {
            DB::beginTransaction();

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

            // Encriptar y asignar la contraseña únicamente si fue provista en el formulario
            if (!empty($this->password)) {
                $data['password'] = Hash::make($this->password);
            }

            if ($this->paciente_id) {
                $paciente = Paciente::findOrFail($this->paciente_id);
                $paciente->update($data);
                $mensaje = 'Paciente actualizado correctamente.';
            } else {
                Paciente::create($data);
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
            $paciente->delete();

            $this->dispatchBrowserEvent('swal-success', ['message' => 'Paciente eliminado correctamente.']);
        } catch (\Exception $e) {
            $this->dispatchBrowserEvent('swal-error', ['message' => 'No se pudo eliminar el paciente debido a relaciones vinculadas.']);
        }
    }

    public function render()
    {
        $centrosSalud = MedicalCenter::orderBy('name', 'asc')->get();

        $pacientes = Paciente::query()
            ->when($this->search, function ($query) {
                $query->where(function ($q) {
                    $q->where('cedula', 'like', '%' . $this->search . '%')
                      ->orWhere('nombres', 'like', '%' . $this->search . '%')
                      ->orWhere('apellidos', 'like', '%' . $this->search . '%');
                });
            })
            ->latest()
            ->paginate(10);

        return view('livewire.medico.list-pacientes', [
            'pacientes' => $pacientes,
            'centrosSalud' => $centrosSalud,
            'allMedicalCenters' => $centrosSalud,
        ]);
    }
}