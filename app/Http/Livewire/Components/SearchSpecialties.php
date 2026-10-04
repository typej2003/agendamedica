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
        return redirect()->route('medicos.search', [
            'country' => $this->selectedCountry,
            'state' => $this->selectedState,
            'specialty' => $this->selectedSpecialty,
        ]);
    }

    public function render()
    {
        return view('livewire.components.search-specialties');
    }
}