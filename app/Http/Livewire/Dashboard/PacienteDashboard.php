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

            // Obtener la cita activa/pendiente (estado = 0 o 'pendiente' y no atendido)
            $this->proximaCita = Cola::with('medicoUser')
                ->where('numhistoria', $numHistoriaUser)
                ->whereDate('fecha', '>=', Carbon::today())
                ->where('atendido', 0)
                ->where(function ($query) {
                    $query->where('estado', 0)
                          ->orWhere('estado', '0')
                          ->orWhere('estado', 'pendiente');
                })
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