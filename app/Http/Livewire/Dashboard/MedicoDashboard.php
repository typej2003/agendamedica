<?php

namespace App\Http\Livewire\Dashboard;

use Livewire\Component;
use App\Models\Medico;
use App\Models\User;
use App\Models\Cola;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class MedicoDashboard extends Component
{
    public function render()
    {
        // 1. Obtener el médico autenticado
        $medico = Medico::where('user_id', Auth::id())->first() ?? Medico::first();

        if ($medico) {
            // 2. Obtener IDs de pacientes asociados al médico
            $pacientesIds = DB::table('medico_pacientes')
                ->where('medico_id', $medico->id)
                ->pluck('paciente_id')
                ->toArray();

            // Total de pacientes asignados a este médico
            $totalPacientes = count($pacientesIds);

            // 3. Buscar usuarios (User) asociados a dichos pacientes
            $pacientesUsuarios = DB::table('pacientes')
                ->whereIn('id', $pacientesIds)
                ->whereNotNull('email')
                ->pluck('email')
                ->toArray();

            $usuariosAsociadosQuery = User::whereIn('email', $pacientesUsuarios);
            $totalUsuarios = $usuariosAsociadosQuery->count();

            // 4. Filtrar cuáles de esos usuarios asociados al médico están en línea
            $todosUsuariosMedico = $usuariosAsociadosQuery->get();
            $listaUsuariosConectados = $todosUsuariosMedico->filter(function ($user) {
                return Cache::has('user-is-online-' . $user->id);
            });

            $usuariosConectados = $listaUsuariosConectados->count();

            // 5. Métricas Simbólicas de Citas / Agendamientos (Pendiente de lógica final)
            $fechaHoy = Carbon::today()->toDateString();
            $fechaManana = Carbon::tomorrow()->toDateString();

            $citasTotales = 0;
            $citasHoy = 0;
            $citasManana = 0;

            // 6. Colección vacía simbólica mientras defines la lógica del modelo Cola
            $proximasCitas = collect();

        } else {
            $totalPacientes = 0;
            $totalUsuarios = 0;
            $usuariosConectados = 0;
            $listaUsuariosConectados = collect();
            
            $citasTotales = 0;
            $citasHoy = 0;
            $citasManana = 0;
            $fechaHoy = Carbon::today()->toDateString();
            $fechaManana = Carbon::tomorrow()->toDateString();
            $medico = (object)['id' => 0];
            $proximasCitas = collect();
        }

        return view('livewire.dashboard.medico-dashboard', compact(
            'totalUsuarios',
            'totalPacientes',
            'usuariosConectados',
            'listaUsuariosConectados',
            'citasTotales',
            'citasHoy',
            'citasManana',
            'fechaHoy',
            'fechaManana',
            'medico',
            'proximasCitas'
        ));
    }
}