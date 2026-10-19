<?php

namespace App\Http\Livewire\Components;

use Livewire\Component;
use App\Models\Specialty;
use App\Models\Country;
use App\Models\Estado;

class SearchSpecialties extends Component
{
    public $selectedCountry = '';
    public $selectedState = '';
    public $selectedSpecialty = '';

    public $countries = [];
    public $states = [];
    public $specialties = [];

    public function mount()
    {
        $this->specialties = Specialty::orderBy('name')->get();
        $this->countries = Country::orderBy('name')->get();

        // Asignar Venezuela por defecto
        $defaultCountry = Country::where('name', 'LIKE', '%Venezuela%')->first();
        if ($defaultCountry) {
            $this->selectedCountry = $defaultCountry->id;
            $this->loadStates();
        }
    }

    public function updatedSelectedCountry()
    {
        $this->selectedState = '';
        $this->loadStates();
    }

    private function loadStates()
    {
        if ($this->selectedCountry) {
            $this->states = Estado::where('country_id', $this->selectedCountry)->orderBy('name')->get();
        } else {
            $this->states = [];
        }
    }

    public function search()
    {
        // El buscador de la portada lleva al **portal público de reservas** (2026-10-19), no al listado
        // viejo: ese terminaba en «Agendar Cita» → login. La especialidad viaja por su slug; el país y el
        // estado quedan fuera de esta primera versión, que muestra todas las sedes con agenda publicada.
        $slug = $this->selectedSpecialty
            ? Specialty::whereKey($this->selectedSpecialty)->value('slug')
            : null;

        return redirect()->route('reservas.catalogo', $slug ? ['especialidad' => $slug] : []);
    }

    public function render()
    {
        return view('livewire.components.search-specialties');
    }
}