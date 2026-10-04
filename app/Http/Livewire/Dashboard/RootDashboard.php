<?php

namespace App\Http\Livewire\Dashboard;

use Livewire\Component;
use App\Models\User;
use App\Models\Paciente;
use App\Models\Medico;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class RootDashboard extends Component
{
    public function render()
    {
        $totalUsuarios = User::count();
        $totalPacientes = Paciente::count();
        $totalMedicos = Medico::count();

        // Obtener usuarios activos en Caché
        $todosLosUsuarios = User::all();
        
        $listaUsuariosConectados = $todosLosUsuarios->filter(function ($user) {
            return Cache::has('user-is-online-' . $user->id);
        });

        $usuariosConectados = $listaUsuariosConectados->count();

        // Métricas de Citas usando la tabla 'cola'
        $fechaHoy = Carbon::today()->toDateString();
        $fechaManana = Carbon::tomorrow()->toDateString();

        $citasTotales = DB::table('cola')->count();
        $citasHoy = DB::table('cola')->whereDate('fecha', $fechaHoy)->count();
        $citasManana = DB::table('cola')->whereDate('fecha', $fechaManana)->count();

        return view('livewire.dashboard.root-dashboard', compact(
            'totalUsuarios',
            'totalPacientes',
            'totalMedicos',
            'usuariosConectados',
            'listaUsuariosConectados',
            'citasTotales',
            'citasHoy',
            'citasManana'
        ));
    }
}