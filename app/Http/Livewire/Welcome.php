<?php

namespace App\Http\Livewire;

use App\Models\Specialty;
use Livewire\Component;

class Welcome extends Component
{
    public $especialidadSeleccionada = null;
    public $especialidades = [];

    protected $queryString = [
        'especialidadSeleccionada' => ['except' => '', 'as' => 'especialidad'],
    ];

    public function mount()
    {
        $this->especialidades = Specialty::withCount('medicos')->orderBy('name')->get();

        if (request()->has('especialidad')) {
            $this->seleccionarEspecialidad(request()->get('especialidad'));
        }
    }

    public function seleccionarEspecialidad($slug)
    {
        $this->especialidadSeleccionada = Specialty::with(['medicos' => function ($query) {
            $query->with(['medicalCenter', 'medicoRegistro']);
        }])->where('slug', $slug)->first();
    }

    public function buscarPorEspecialidad($specialtyId)
    {
        return redirect()->route('medicos.search', [
            'specialty' => $specialtyId,
        ]);
    }

    public function limpiarFiltro()
    {
        $this->especialidadSeleccionada = null;
    }

    public function render()
    {
        return view('livewire.welcome');
    }
}