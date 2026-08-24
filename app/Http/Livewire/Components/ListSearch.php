<?php

namespace App\Http\Livewire\Components;

use Livewire\Component;
use Livewire\WithPagination;
use App\Models\Specialty;
use App\Models\Country;
use App\Models\Estado;
use App\Models\Medico;

class ListSearch extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    // Mapeo de parámetros en la URL (?country=X&state=Y&specialty=Z&search=W)
    protected $queryString = [
        'searchDoctor' => ['except' => '', 'as' => 'search'],
        'selectedCountry' => ['except' => null, 'as' => 'country'],
        'selectedState' => ['except' => '', 'as' => 'state'],
        'selectedSpecialty' => ['except' => '0', 'as' => 'specialty'],
    ];

    // Propiedades de búsqueda
    public $searchDoctor = '';
    public $selectedSpecialty = '0';
    public $selectedCountry = null;
    public $selectedState = '';

    // Colecciones para selects
    public $countries = [];
    public $states = [];
    public $specialties = [];

    public function mount()
    {
        // Carga de catálogos base
        $this->specialties = Specialty::orderBy('name')->get();
        $this->countries = Country::orderBy('name')->get();

        // Si no viene un país especificado desde el buscador principal, asignar Venezuela por defecto
        if (!$this->selectedCountry) {
            $defaultCountry = Country::where('name', 'LIKE', '%Venezuela%')->first();
            if ($defaultCountry) {
                $this->selectedCountry = $defaultCountry->id;
            }
        }

        // Cargar listas dependientes basadas en los parámetros recibidos en el mount
        $this->loadStates();
    }

    public function updatedSelectedCountry()
    {
        $this->selectedState = '';
        $this->loadStates();
        $this->resetPage();
    }

    public function updatedSelectedState()
    {
        $this->resetPage();
    }

    public function updatedSearchDoctor()
    {
        $this->resetPage();
    }

    public function updatedSelectedSpecialty()
    {
        $this->resetPage();
    }

    private function loadStates()
    {
        if ($this->selectedCountry) {
            $this->states = Estado::where('country_id', $this->selectedCountry)->orderBy('name')->get();
        } else {
            $this->states = [];
        }
    }

    public function render()
    {
        // Verificar si existe al menos un filtro de búsqueda activo
        $hasSearch = !empty(trim($this->searchDoctor));
        $hasSpecialty = !empty($this->selectedSpecialty) && $this->selectedSpecialty !== '0';
        $hasCountry = !empty($this->selectedCountry);
        $hasState = !empty($this->selectedState);

        // Si todos los controles están vacíos o por defecto, retornar resultado vacío
        if (!$hasSearch && !$hasSpecialty && !$hasCountry && !$hasState) {
            return view('livewire.components.list-search', [
                'medicos' => Medico::whereRaw('1 = 0')->paginate(6),
            ]);
        }

        $query = Medico::query()
            ->with(['specialties', 'office.medicalCenter.city', 'office.medicalCenter.estado'])
            ->where('is_active', true);

        // Filtro por nombre o apellido
        if ($hasSearch) {
            $query->where(function ($q) {
                $q->where('name', 'LIKE', '%' . trim($this->searchDoctor) . '%')
                  ->orWhere('lastname', 'LIKE', '%' . trim($this->searchDoctor) . '%');
            });
        }

        // Filtro por especialidad
        if ($hasSpecialty) {
            $query->whereHas('specialties', function ($q) {
                $q->where('specialties.id', $this->selectedSpecialty);
            });
        }

        // Filtros geográficos acumulativos
        if ($hasState) {
            $query->whereHas('office.medicalCenter', function ($q) {
                $q->where('state_id', $this->selectedState);
            });
        } elseif ($hasCountry) {
            $query->whereHas('office.medicalCenter', function ($q) {
                $q->where('country_id', $this->selectedCountry);
            });
        }

        $medicos = $query->paginate(6);

        return view('livewire.components.list-search', [
            'medicos' => $medicos,
        ]);
    }
}