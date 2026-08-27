<?php

namespace App\Http\Livewire\Medico;

use Livewire\Component;
use Livewire\WithPagination;
use App\Models\Paciente;
use App\Models\Medico;
use Illuminate\Support\Facades\Auth;

class ListPacientes extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    public $search = '';
    public $paciente_id;
    
    // Campos del Paciente
    public $nac = 'V', $cedula, $nombres, $apellidos, $sexo, $telefono, $email, $direccion;
    public $fnacimiento, $lnacimiento, $escolaridad, $ocupacion, $profesion;
    
    // Campo propio de la relación Medico-Paciente (Pivote)
    public $numhistoria;

    protected $rules = [
        'nac' => 'nullable|string|max:2',
        'cedula' => 'required|string|max:20',
        'nombres' => 'required|string|max:255',
        'apellidos' => 'required|string|max:255',
        'sexo' => 'nullable|string|max:1',
        'telefono' => 'nullable|string|max:50',
        'email' => 'nullable|email|max:255',
        'direccion' => 'nullable|string',
        'fnacimiento' => 'nullable|date',
        'lnacimiento' => 'nullable|string|max:255',
        'escolaridad' => 'nullable|string|max:100',
        'ocupacion' => 'nullable|string|max:100',
        'profesion' => 'nullable|string|max:100',
        'numhistoria' => 'nullable|string|max:50',
    ];

    private function getMedicoActual()
    {
        return Medico::where('user_id', Auth::id())->firstOrFail();
    }

    public function updatingSearch()
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
        $this->fnacimiento = null;
        $this->lnacimiento = '';
        $this->escolaridad = '';
        $this->ocupacion = '';
        $this->profesion = '';
        $this->numhistoria = '';
        $this->resetValidation();
    }

    public function create()
    {
        $this->resetFields();
        $this->dispatch('open-modal-paciente');
    }

    public function edit($id)
    {
        $medico = $this->getMedicoActual();
        
        // Consultar el paciente garantizando que pertenece al médico logueado
        $paciente = $medico->pacientes()->findOrFail($id);

        $this->paciente_id = $paciente->id;
        $this->nac = $paciente->nac;
        $this->cedula = $paciente->cedula;
        $this->nombres = $paciente->nombres;
        $this->apellidos = $paciente->apellidos;
        $this->sexo = $paciente->sexo;
        $this->telefono = $paciente->telefono;
        $this->email = $paciente->email;
        $this->direccion = $paciente->direccion;
        $this->fnacimiento = $paciente->fnacimiento ? $paciente->fnacimiento->format('Y-m-d') : null;
        $this->lnacimiento = $paciente->lnacimiento;
        $this->escolaridad = $paciente->escolaridad;
        $this->ocupacion = $paciente->ocupacion;
        $this->profesion = $paciente->profesion;

        // Cargar el número de historia de la tabla pivote medico_pacientes
        $this->numhistoria = $paciente->pivot->numhistoria ?? '';

        $this->dispatch('open-modal-paciente');
    }

    public function save()
    {
        $medico = $this->getMedicoActual();

        $rules = $this->rules;
        $rules['cedula'] = 'required|string|max:20|unique:pacientes,cedula,' . $this->paciente_id;

        $this->validate($rules);

        // 1. Guardar o actualizar la información global del paciente
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

        // 2. Asociar el paciente con el médico actual en la tabla pivote con su numhistoria
        $medico->pacientes()->syncWithoutDetaching([
            $paciente->id => [
                'numhistoria' => $this->numhistoria
            ]
        ]);

        $this->dispatch('close-modal-paciente');
        session()->flash('message', $this->paciente_id ? 'Paciente actualizado correctamente.' : 'Paciente registrado correctamente.');
        $this->resetFields();
    }

    public function delete($id)
    {
        $medico = $this->getMedicoActual();

        // Se desvincula la relación pivote del médico con el paciente
        $medico->pacientes()->detach($id);

        session()->flash('message', 'Paciente removido de su lista correctamente.');
    }

    public function render()
    {
        $medico = $this->getMedicoActual();

        // Obtener solo los pacientes vinculados al médico en sesión
        $pacientes = $medico->pacientes()
            ->where(function ($query) {
                $query->where('nombres', 'like', '%' . $this->search . '%')
                    ->orWhere('apellidos', 'like', '%' . $this->search . '%')
                    ->orWhere('cedula', 'like', '%' . $this->search . '%')
                    ->orWhere('medico_pacientes.numhistoria', 'like', '%' . $this->search . '%');
            })
            ->orderBy('pacientes.id', 'desc')
            ->paginate(15);

        return view('livewire.medico.list-pacientes', compact('pacientes'));
    }
}