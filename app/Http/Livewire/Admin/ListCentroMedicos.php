<?php

namespace App\Http\Livewire\Admin;

use Livewire\Component;
use Livewire\WithPagination;
use App\Models\MedicalCenter;

class ListCentroMedicos extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    public $search = '';
    public $centro_id;
    public $name, $address, $phone, $email;
    public $is_active = true;

    protected $rules = [
        'name' => 'required|string|max:255',
        'address' => 'nullable|string',
        'phone' => 'nullable|string|max:50',
        'email' => 'nullable|email|max:255',
        'is_active' => 'boolean',
    ];

    public function updatingSearch()
    {
        $this->resetPage();
    }

    public function resetFields()
    {
        $this->centro_id = null;
        $this->name = '';
        $this->address = '';
        $this->phone = '';
        $this->email = '';
        $this->is_active = true;
        $this->resetValidation();
    }

    public function create()
    {
        $this->resetFields();
        $this->dispatch('open-modal-centro');
    }

    public function edit($id)
    {
        $centro = MedicalCenter::findOrFail($id);
        $this->centro_id = $centro->id;
        $this->name = $centro->name;
        $this->address = $centro->address;
        $this->phone = $centro->phone;
        $this->email = $centro->email;
        $this->is_active = (bool) $centro->is_active;

        $this->dispatch('open-modal-centro');
    }

    public function save()
    {
        $this->validate();

        MedicalCenter::updateOrCreate(
            ['id' => $this->centro_id],
            [
                'name' => $this->name,
                'address' => $this->address,
                'phone' => $this->phone,
                'email' => $this->email,
                'is_active' => $this->is_active,
            ]
        );

        $this->dispatch('close-modal-centro');
        session()->flash('message', $this->centro_id ? 'Centro Médico actualizado correctamente.' : 'Centro Médico registrado correctamente.');
        $this->resetFields();
    }

    public function delete($id)
    {
        MedicalCenter::findOrFail($id)->delete();
        session()->flash('message', 'Centro Médico eliminado correctamente.');
    }

    public function render()
    {
        $centros = MedicalCenter::where('name', 'like', '%' . $this->search . '%')
            ->orderBy('id', 'desc')
            ->paginate(15);

        return view('livewire.admin.list-centro-medicos', compact('centros'));
    }
}