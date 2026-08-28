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

        // Obtener todos los usuarios y filtrar los activos en Caché
        $todosLosUsuarios = User::all();
        
        $listaUsuariosConectados = $todosLosUsuarios->filter(function ($user) {
            return Cache::has('user-is-online-' . $user->id);
        });

        $usuariosConectados = $listaUsuariosConectados->count();

        // Métricas de Citas Globales (Todos los médicos)
        $fechaHoy = Carbon::today()->toDateString();
        $fechaManana = Carbon::tomorrow()->toDateString();

        $citasTotales = DB::table('appointments')->count();
        $citasHoy = DB::table('appointments')->whereDate('date', $fechaHoy)->count();
        $citasManana = DB::table('appointments')->whereDate('date', $fechaManana)->count();

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