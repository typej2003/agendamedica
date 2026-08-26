<?php

namespace App\Http\Livewire\Admin;

use Livewire\Component;
use Livewire\WithPagination;
use App\Models\Medico;

class ListMedicos extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    public $search = '';
    public $medico_id;
    public $name, $lastname, $license_number, $phone, $email, $reg_medico;
    public $is_active = true;

    protected $rules = [
        'name' => 'required|string|max:255',
        'lastname' => 'required|string|max:255',
        'license_number' => 'nullable|string|max:100',
        'phone' => 'nullable|string|max:50',
        'email' => 'nullable|email|max:255',
        'reg_medico' => 'required|string|max:100',
        'is_active' => 'boolean',
    ];

    public function updatingSearch()
    {
        $this->resetPage();
    }

    public function resetFields()
    {
        $this->medico_id = null;
        $this->name = '';
        $this->lastname = '';
        $this->license_number = '';
        $this->phone = '';
        $this->email = '';
        $this->reg_medico = '';
        $this->is_active = true;
        $this->resetValidation();
    }

    public function create()
    {
        $this->resetFields();
        $this->dispatch('open-modal-medico');
    }

    public function edit($id)
    {
        $medico = Medico::findOrFail($id);
        $this->medico_id = $medico->id;
        $this->name = $medico->name;
        $this->lastname = $medico->lastname;
        $this->license_number = $medico->license_number;
        $this->phone = $medico->phone;
        $this->email = $medico->email;
        $this->reg_medico = $medico->{'reg-medico'};
        $this->is_active = (bool) $medico->is_active;

        $this->dispatch('open-modal-medico');
    }

    public function save()
    {
        $rules = $this->rules;
        $rules['reg_medico'] = 'required|string|max:100|unique:medicos,reg-medico,' . $this->medico_id;

        $this->validate($rules);

        Medico::updateOrCreate(
            ['id' => $this->medico_id],
            [
                'name' => $this->name,
                'lastname' => $this->lastname,
                'license_number' => $this->license_number,
                'phone' => $this->phone,
                'email' => $this->email,
                'reg-medico' => $this->reg_medico,
                'is_active' => $this->is_active,
            ]
        );

        $this->dispatch('close-modal-medico');
        session()->flash('message', $this->medico_id ? 'Médico actualizado exitosamente.' : 'Médico registrado exitosamente.');
        $this->resetFields();
    }

    public function delete($id)
    {
        Medico::findOrFail($id)->delete();
        session()->flash('message', 'Médico eliminado correctamente.');
    }

    public function render()
    {
        $medicos = Medico::where('name', 'like', '%' . $this->search . '%')
            ->orWhere('lastname', 'like', '%' . $this->search . '%')
            ->orWhere('reg-medico', 'like', '%' . $this->search . '%')
            ->orderBy('id', 'desc')
            ->paginate(15);

        return view('livewire.admin.list-medicos', compact('medicos'));
    }
}