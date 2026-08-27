<?php

namespace App\Http\Livewire\Medico;

use Livewire\Component;
use Livewire\WithPagination;
use App\Models\Paciente;

class ListPacientes extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    public $search = '';
    public $paciente_id;
    public $nac = 'V', $cedula, $nombres, $apellidos, $sexo, $telefono, $email, $direccion;

    protected $rules = [
        'nac' => 'nullable|string|max:2',
        'cedula' => 'required|string|max:20',
        'nombres' => 'required|string|max:255',
        'apellidos' => 'required|string|max:255',
        'sexo' => 'nullable|string|max:1',
        'telefono' => 'nullable|string|max:50',
        'email' => 'nullable|email|max:255',
        'direccion' => 'nullable|string',
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
        $this->resetValidation();
    }

    public function create()
    {
        $this->resetFields();
        $this->dispatch('open-modal-paciente');
    }

    public function edit($id)
    {
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

        $this->dispatch('open-modal-paciente');
    }

    public function save()
    {
        $rules = $this->rules;
        $rules['cedula'] = 'required|string|max:20|unique:pacientes,cedula,' . $this->paciente_id;

        $this->validate($rules);

        Paciente::updateOrCreate(
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
            ]
        );

        $this->dispatch('close-modal-paciente');
        session()->flash('message', $this->paciente_id ? 'Paciente actualizado correctamente.' : 'Paciente registrado correctamente.');
        $this->resetFields();
    }

    public function delete($id)
    {
        Paciente::findOrFail($id)->delete();
        session()->flash('message', 'Paciente eliminado correctamente.');
    }

    public function render()
    {
        $pacientes = Paciente::where('nombres', 'like', '%' . $this->search . '%')
            ->orWhere('apellidos', 'like', '%' . $this->search . '%')
            ->orWhere('cedula', 'like', '%' . $this->search . '%')
            ->orderBy('id', 'desc')
            ->paginate(15);

        return view('livewire.medico.list-pacientes', compact('pacientes'));
    }
}