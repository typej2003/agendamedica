<?php

namespace App\Http\Livewire\Dashboard;

use Livewire\Component;
use App\Models\Medico;
use App\Models\MedicoRegistro;
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
            // Obtener el registro médico asociado (reg-medico / reg_medico)
            $medicoRegistro = MedicoRegistro::where('medico_id', $medico->id)->first();

            $regMedico = $medicoRegistro->{'reg-medico'} 
                        ?? $medicoRegistro->reg_medico 
                        ?? $medico->{'reg-medico'} 
                        ?? $medico->reg_medico 
                        ?? null;

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

            // 5. Métricas de Citas / Agendamientos desde la tabla cola usando reg_medico
            $fechaHoy = Carbon::today()->toDateString();
            $fechaManana = Carbon::tomorrow()->toDateString();

            // Consulta base filtrando por reg_medico
            $queryCitasMedico = Cola::query();

            if (!empty($regMedico)) {
                $queryCitasMedico->where('reg_medico', $regMedico);
            } else {
                $queryCitasMedico->whereRaw('1 = 0');
            }

            // Conteo total de citas agendadas para el médico
            $citasTotales = (clone $queryCitasMedico)->count();

            // Conteo de citas programadas para hoy
            $citasHoy = (clone $queryCitasMedico)
                ->whereDate('fecha', $fechaHoy)
                ->count();

            // Conteo de citas programadas para mañana
            $citasManana = (clone $queryCitasMedico)
                ->whereDate('fecha', $fechaManana)
                ->count();

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
            'medico'
        ));
    }
}