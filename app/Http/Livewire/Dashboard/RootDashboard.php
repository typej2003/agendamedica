<?php

namespace App\Http\Livewire\Dashboard;

use Livewire\Component;
use App\Models\User;
use App\Models\Paciente;
use App\Models\Medico;

class RootDashboard extends Component
{
    public function render()
    {
        $totalUsuarios = User::count();
        $totalPacientes = Paciente::count();
        $totalMedicos = Medico::count();

        return view('livewire.dashboard.root-dashboard', compact(
            'totalUsuarios',
            'totalPacientes',
            'totalMedicos'
        ));
    }
}