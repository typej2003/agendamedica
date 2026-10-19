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
        // La tarjeta de la especialidad lleva al **portal público de reservas**, que muestra solo a los
        // médicos con agenda publicada. El listado viejo (`medicos.search`) sigue existiendo pero ya no es
        // la puerta: su «Agendar» mandaba al login.
        $slug = Specialty::whereKey($specialtyId)->value('slug');

        return redirect()->route('reservas.catalogo', $slug ? ['especialidad' => $slug] : []);
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