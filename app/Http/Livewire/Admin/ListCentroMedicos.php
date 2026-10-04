<?php

namespace App\Http\Livewire\Admin;

use Livewire\Component;
use Livewire\WithPagination;
use App\Models\MedicalCenter;
use App\Models\Country;
use App\Models\Estado;
use App\Models\City;

class ListCentroMedicos extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    public $search = '';
    public $centro_id;
    public $name, $address, $phone, $email;
    public $is_active = true;

    // Propiedades para ubicación dinámica
    public $country_id;
    public $state_id;
    public $city_id;

    public $estados = [];
    public $ciudades = [];

    protected $listeners = [
        'confirmDelete' => 'delete'
    ];

    protected $rules = [
        'name' => 'required|string|max:255',
        'address' => 'nullable|string',
        'phone' => 'nullable|string|max:50',
        'email' => 'nullable|email|max:255',
        'is_active' => 'boolean',
        'country_id' => 'required|exists:countries,id',
        'state_id' => 'nullable|exists:estados,id',
        'city_id' => 'nullable|exists:cities,id',
    ];

    public function mount()
    {
        $this->setDefaultCountry();
    }

    private function setDefaultCountry()
    {
        // Establecer Venezuela por defecto (por nombre o ISO si aplica)
        $venezuela = Country::where('name', 'like', '%Venezuela%')->first();
        
        if ($venezuela) {
            $this->country_id = $venezuela->id;
            $this->updatedCountryId($this->country_id);
        }
    }

    public function updatedCountryId($value)
    {
        if (!empty($value)) {
            $this->estados = Estado::where('country_id', $value)->orderBy('name', 'asc')->get();
        } else {
            $this->estados = [];
        }
        $this->state_id = null;
        $this->ciudades = [];
        $this->city_id = null;
    }

    public function updatedStateId($value)
    {
        if (!empty($value)) {
            $this->ciudades = City::where('state_id', $value)->orderBy('name', 'asc')->get();
        } else {
            $this->ciudades = [];
        }
        $this->city_id = null;
    }

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
        
        $this->setDefaultCountry();
        $this->resetValidation();
    }

    public function create()
    {
        $this->resetFields();
        $this->dispatchBrowserEvent('open-modal-centro');
    }

    public function edit($id)
    {
        $this->resetValidation();
        $centro = MedicalCenter::findOrFail($id);

        $this->centro_id = $centro->id;
        $this->name = $centro->name;
        $this->address = $centro->address;
        $this->phone = $centro->phone;
        $this->email = $centro->email;
        $this->is_active = (bool) $centro->is_active;

        $this->country_id = $centro->country_id;
        if ($this->country_id) {
            $this->estados = Estado::where('country_id', $this->country_id)->orderBy('name', 'asc')->get();
        }

        $this->state_id = $centro->state_id;
        if ($this->state_id) {
            $this->ciudades = City::where('state_id', $this->state_id)->orderBy('name', 'asc')->get();
        }

        $this->city_id = $centro->city_id;

        $this->dispatchBrowserEvent('open-modal-centro');
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
                'country_id' => $this->country_id,
                'state_id' => $this->state_id ?: null,
                'city_id' => $this->city_id ?: null,
            ]
        );

        $this->dispatchBrowserEvent('close-modal-centro');
        session()->flash('message', $this->centro_id ? 'Centro Médico actualizado correctamente.' : 'Centro Médico registrado correctamente.');
        $this->resetFields();
    }

    public function triggerDeleteConfirm($id)
    {
        $this->dispatchBrowserEvent('show-delete-confirmation', ['id' => $id]);
    }

    public function delete($id)
    {
        MedicalCenter::findOrFail($id)->delete();
        session()->flash('message', 'Centro Médico eliminado correctamente.');
    }

    public function render()
    {
        $centros = MedicalCenter::with(['country', 'estado', 'city'])
            ->where('name', 'like', '%' . $this->search . '%')
            ->orderBy('id', 'desc')
            ->paginate(15);

        $paises = Country::orderBy('name', 'asc')->get();

        return view('livewire.admin.list-centro-medicos', compact('centros', 'paises'));
    }
}