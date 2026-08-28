<?php

namespace App\Http\Livewire\Components;

use Livewire\Component;
use App\Models\Medico;
use App\Models\Cola;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

class DaySchedule extends Component
{
    public $medicoId;
    public $fecha;
    public $medico;
    public $horasDisponibles = [];
    public $citasExistentes = [];
    public $esPersonalAutorizado = false;

    // Horarios de atención predeterminados (8:00 AM a 6:00 PM)
    public $horaInicio = '08:00';
    public $horaFin = '18:00';
    public $intervaloMinutos = 30;

    public function mount($medicoId, $fecha)
    {
        $this->medicoId = $medicoId;
        $this->fecha = $fecha;
        $this->medico = Medico::findOrFail($medicoId);

        // Control de Accesos por Rol
        /** @var User $user */
        $user = Auth::user();
        if ($user && ($user->hasRole('Root') || $user->hasRole('Medico') || $user->hasRole('Secretaria'))) {
            $this->esPersonalAutorizado = true;
        }

        $this->cargarHorarios();
    }

    public function cargarHorarios()
    {
        // Consultar citas existentes en el modelo Cola
        $citas = Cola::where('medico_id', $this->medicoId)
            ->where('fecha', $this->fecha)
            ->get();

        $this->citasExistentes = $citas->keyBy('hora_ini')->toArray();

        // Generar Bloques de Horas desde las 08:00 AM hasta las 06:00 PM
        $inicio = Carbon::createFromFormat('H:i', $this->horaInicio);
        $fin = Carbon::createFromFormat('H:i', $this->horaFin);

        $slots = [];
        while ($inicio < $fin) {
            $horaStr = $inicio->format('H:i:s');
            $horaDisplay = $inicio->format('h:i A');

            $ocupada = isset($this->citasExistentes[$horaStr]);

            $slots[] = [
                'hora_ini' => $horaStr,
                'display' => $horaDisplay,
                'ocupada' => $ocupada,
                'cita' => $ocupada ? $this->citasExistentes[$horaStr] : null,
            ];

            $inicio->addMinutes($this->intervaloMinutos);
        }

        $this->horasDisponibles = $slots;
    }

    public function agendarHora($horaIni)
    {
        /** @var User $user */
        $user = Auth::user();

        // Verificar si la hora sigue disponible
        $existe = Cola::where('medico_id', $this->medicoId)
            ->where('fecha', $this->fecha)
            ->where('hora_ini', $horaIni)
            ->exists();

        if ($existe) {
            session()->flash('error', 'Esta hora ya ha sido ocupada por otro paciente.');
            $this->cargarHorarios();
            return;
        }

        $horaFinCalculada = Carbon::createFromFormat('H:i:s', $horaIni)
            ->addMinutes($this->intervaloMinutos)
            ->format('H:i:s');

        // Obtener correlativo de número de orden
        $ultimoNumOrden = Cola::where('medico_id', $this->medicoId)
            ->where('fecha', $this->fecha)
            ->max('numorden') ?? 0;

        // Registrar en el Modelo Cola
        Cola::create([
            'medico_id'   => $this->medicoId,
            'reg-medico'  => $this->medico->license_number ?? 'REG-000',
            'fecha'       => $this->fecha,
            'numhistoria' => $user->numhistoria ?? $user->id,
            'numorden'    => $ultimoNumOrden + 1,
            'atendido'    => 0,
            'estado'      => 'Pendiente',
            'turno'       => Carbon::createFromFormat('H:i:s', $horaIni)->format('A'),
            'motivo'      => 'Consulta Médica Generada por Sistema',
            'monto'       => $this->medico->consultation_fee ?? 0.00,
            'hora_ini'    => $horaIni,
            'hora_fin'    => $horaFinCalculada,
            'tiempo'      => $this->intervaloMinutos,
            'tipo'        => 'Cita',
            'medico'      => $this->medico->name . ' ' . $this->medico->lastname,
        ]);

        session()->flash('message', '¡Cita agendada exitosamente!');
        $this->cargarHorarios();
    }

    public function render()
    {
        return view('livewire.components.day-schedule');
    }
}