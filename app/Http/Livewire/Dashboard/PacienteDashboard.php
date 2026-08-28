<?php

namespace App\Http\Livewire\Dashboard;

use Livewire\Component;
use App\Models\Cola;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;

class PacienteDashboard extends Component
{
    public $proximaCita = null;

    public function mount()
    {
        $this->obtenerProximaCita();
    }

    public function obtenerProximaCita()
    {
        /** @var User $user */
        $user = Auth::user();

        if ($user) {
            $numHistoriaUser = $user->numhistoria ?? $user->id;

            // Obtener la cita pendiente más cercana (de hoy en adelante)
            $this->proximaCita = Cola::with('medico')
                ->where('numhistoria', $numHistoriaUser)
                ->whereDate('fecha', '>=', Carbon::today())
                ->where('atendido', 0)
                ->orderBy('fecha', 'asc')
                ->orderBy('numorden', 'asc')
                ->first();
        }
    }

    public function render()
    {
        return view('livewire.dashboard.paciente-dashboard');
    }
}