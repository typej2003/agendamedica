<?php

namespace App\Http\Livewire\Dashboard;

use Livewire\Component;
use App\Models\User;
use App\Models\Paciente;
use App\Models\Medico;
use Illuminate\Support\Facades\Cache;

class RootDashboard extends Component
{
    public function render()
    {
        $totalUsuarios = User::count();
        $totalPacientes = Paciente::count();
        $totalMedicos = Medico::count();

        // Obtener todos los usuarios y filtrar los activos en Caché
        $todosLosUsuarios = User::all();
        
        $listaUsuariosConectados = $todosLosUsuarios->filter(function ($user) {
            return Cache::has('user-is-online-' . $user->id);
        });

        $usuariosConectados = $listaUsuariosConectados->count();

        return view('livewire.dashboard.root-dashboard', compact(
            'totalUsuarios',
            'totalPacientes',
            'totalMedicos',
            'usuariosConectados',
            'listaUsuariosConectados'
        ));
    }
}