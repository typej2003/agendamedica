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
    
    // Propiedades del formulario
    public $nac = 'V', $cedula, $nombres, $apellidos, $sexo, $telefono, $email, $direccion;
    public $numhistoria, $fnacimiento, $lnacimiento, $escolaridad, $ocupacion, $profesion;

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
    ];

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
        $this->numhistoria = '';
        $this->fnacimiento = '';
        $this->lnacimiento = '';
        $this->escolaridad = '';
        $this->ocupacion = '';
        $this->profesion = '';
        $this->resetValidation();
    }

    public function create()
    {
        $this->resetFields();
        $this->dispatchBrowserEvent('open-modal-paciente');
    }

    public function edit($id)
    {
        $medico = Medico::where('user_id', Auth::id())->first();

        // Se busca el paciente y se obtiene la relación pivote con el médico actual
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

        // Cargar el número de historia registrado en la pivote para este médico
        if ($medico) {
            $pivotData = $medico->pacientes()->where('paciente_id', $id)->first();
            $this->numhistoria = $pivotData ? $pivotData->pivot->numhistoria : '';
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

        // Vincular o actualizar datos en la tabla pivote medico_pacientes
        $medico = Medico::where('user_id', Auth::id())->first();
        if ($medico) {
            $medico->pacientes()->syncWithoutDetaching([
                $paciente->id => [
                    'numhistoria' => $this->numhistoria,
                    'reg-medico' => $medico->{'reg-medico'} ?? null,
                ]
            ]);
        }

        $this->dispatchBrowserEvent('close-modal-paciente');
        session()->flash('message', $this->paciente_id ? 'Paciente actualizado correctamente.' : 'Paciente registrado correctamente.');
        $this->resetFields();
    }

    public function delete($id)
    {
        $medico = Medico::where('user_id', Auth::id())->first();
        if ($medico) {
            // Desvincular al paciente de este médico en la tabla pivote
            $medico->pacientes()->detach($id);
        }
        
        session()->flash('message', 'Paciente removido de su lista correctamente.');
    }

    public function render()
    {
        $medico = Medico::where('user_id', Auth::id())->first();

        if ($medico) {
            $pacientes = $medico->pacientes()
                ->withPivot('numhistoria', 'reg-medico')
                ->where(function ($query) {
                    $query->where('nombres', 'like', '%' . $this->search . '%')
                        ->orWhere('apellidos', 'like', '%' . $this->search . '%')
                        ->orWhere('cedula', 'like', '%' . $this->search . '%')
                        ->orWhere('medico_pacientes.numhistoria', 'like', '%' . $this->search . '%');
                })
                ->orderBy('pacientes.id', 'desc')
                ->paginate(15);
        } else {
            $pacientes = collect([])->paginate(15);
        }

        return view('livewire.medico.list-pacientes', compact('pacientes'));
    }
}