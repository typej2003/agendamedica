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
    
    // Configuración de Modos de Atención: 'cupos' (por defecto) u 'horario'
    public $modoAtencion = 'cupos'; 
    public $maxCupos = 10;          // Cantidad máxima de cupos por día
    
    // Configuración para el Modo Horario
    public $horaInicio = '08:00';
    public $horaFin = '18:00';
    public $intervaloMinutos = 30;

    public $itemsSlots = [];
    public $esPersonalAutorizado = false;
    public $miCitaDelDia = null;

    public function mount($medicoId, $fecha)
    {
        Carbon::setLocale('es');

        // Validar que no se ingrese directamente a una fecha pasada mediante URL
        if (Carbon::parse($fecha)->lt(Carbon::today())) {
            session()->flash('error', 'No se pueden agendar o gestionar citas en fechas anteriores.');
            redirect()->route('agendar.cita', ['medicoId' => $medicoId]);
            return;
        }

        $this->medicoId = $medicoId;
        $this->fecha = $fecha;
        $this->medico = Medico::findOrFail($medicoId);

        /** @var User $user */
        $user = Auth::user();
        if ($user && ($user->hasRole('Root') || $user->hasRole('Medico') || $user->hasRole('Secretaria'))) {
            $this->esPersonalAutorizado = true;
        }

        $this->cargarAgenda();
    }

    public function cambiarModo($nuevoModo)
    {
        $this->modoAtencion = $nuevoModo;
        $this->cargarAgenda();
    }

    public function cargarAgenda()
    {
        /** @var User $user */
        $user = Auth::user();
        $numHistoriaUser = $user->numhistoria ?? $user->id;

        $citas = Cola::where('medico_id', $this->medicoId)
            ->whereDate('fecha', $this->fecha)
            ->orderBy('numorden', 'asc')
            ->get();

        // Verificar si el usuario ya tiene cita agendada hoy
        $this->miCitaDelDia = $citas::where('numhistoria', $numHistoriaUser)->first();

        if ($this->modoAtencion === 'cupos') {
            $this->generarAgendaPorCupos($citas);
        } else {
            $this->generarAgendaPorHorarios($citas);
        }
    }

    private function generarAgendaPorCupos($citas)
    {
        $citasPorOrden = $citas->keyBy('numorden');
        $slots = [];
        $now = Carbon::now();
        $esHoy = Carbon::parse($this->fecha)->isToday();

        for ($i = 1; $i <= $this->maxCupos; $i++) {
            $cita = $citasPorOrden->get($i);
            $ocupada = !is_null($cita);

            // En modo cupos, si es hoy y ya pasó la jornada (ej. 6:00 PM), deshabilitar
            $pasado = $esHoy && $now->hour >= 18;

            $slots[] = [
                'numorden' => $i,
                'hora_ini' => null,
                'display' => "Cupo #{$i}",
                'ocupada' => $ocupada,
                'pasado'  => $pasado,
                'cita'    => $cita,
            ];
        }

        $this->itemsSlots = $slots;
    }

    private function generarAgendaPorHorarios($citas)
    {
        $citasPorHora = $citas->keyBy(function ($item) {
            return Carbon::parse($item->hora_ini)->format('H:i:s');
        });

        $inicio = Carbon::createFromFormat('H:i', $this->horaInicio);
        $fin = Carbon::createFromFormat('H:i', $this->horaFin);
        $now = Carbon::now();
        $esHoy = Carbon::parse($this->fecha)->isToday();

        $slots = [];
        $orden = 1;

        while ($inicio < $fin) {
            $horaStr = $inicio->format('H:i:s');
            $horaDisplay = $inicio->format('h:i A');

            $cita = $citasPorHora->get($horaStr);
            $ocupada = !is_null($cita);

            // Validar si el horario ya pasó en el día en curso
            $pasado = $esHoy && $inicio->format('H:i') < $now->format('H:i');

            $slots[] = [
                'numorden' => $orden,
                'hora_ini' => $horaStr,
                'display' => $horaDisplay,
                'ocupada' => $ocupada,
                'pasado'  => $pasado,
                'cita'    => $cita,
            ];

            $inicio->addMinutes($this->intervaloMinutos);
            $orden++;
        }

        $this->itemsSlots = $slots;
    }

    public function agendarCupo($numorden, $horaIni = null)
    {
        /** @var User $user */
        $user = Auth::user();
        $numHistoriaUser = $user->numhistoria ?? $user->id;

        // Validar que el paciente solo tome UNA cita al mes con este médico
        $startOfMonth = Carbon::parse($this->fecha)->startOfMonth()->toDateString();
        $endOfMonth = Carbon::parse($this->fecha)->endOfMonth()->toDateString();

        $tieneCitaEnMes = Cola::where('medico_id', $this->medicoId)
            ->where('numhistoria', $numHistoriaUser)
            ->whereBetween('fecha', [$startOfMonth, $endOfMonth])
            ->exists();

        if ($tieneCitaEnMes) {
            session()->flash('error', 'Solo tiene permitido agendar 1 cita al mes con este médico.');
            return;
        }

        // Validar disponibilidad
        $query = Cola::where('medico_id', $this->medicoId)
            ->whereDate('fecha', $this->fecha);

        if ($horaIni) {
            $query->where('hora_ini', $horaIni);
        } else {
            $query->where('numorden', $numorden);
        }

        if ($query->exists()) {
            session()->flash('error', 'Este turno o cupo ya ha sido ocupado.');
            $this->cargarAgenda();
            return;
        }

        $horaFinCalculada = $horaIni ? Carbon::createFromFormat('H:i:s', $horaIni)->addMinutes($this->intervaloMinutos)->format('H:i:s') : null;
        $turno = $horaIni ? Carbon::createFromFormat('H:i:s', $horaIni)->format('A') : 'AM';

        // Guardar cita en el modelo Cola (estado = 0 por defecto decimal)
        Cola::create([
            'medico_id'   => $this->medicoId,
            'reg-medico'  => $this->medico->license_number ?? 'REG-000',
            'fecha'       => $this->fecha,
            'numhistoria' => $numHistoriaUser,
            'numorden'    => $numorden,
            'atendido'    => 0,
            'estado'      => 0, // 0 = Pendiente (Decimal/Entero)
            'turno'       => $turno,
            'motivo'      => 'Consulta Médica Generada por Sistema',
            'monto'       => $this->medico->consultation_fee ?? 0.00,
            'hora_ini'    => $horaIni,
            'hora_fin'    => $horaFinCalculada,
            'tiempo'      => $this->intervaloMinutos,
            'tipo'        => 'Cita',
            'medico'      => $this->medico->name . ' ' . $this->medico->lastname,
        ]);

        session()->flash('message', '¡Cita agendada exitosamente!');
        $this->cargarAgenda();
    }

    public function eliminarCita($colaId)
    {
        $cita = Cola::findOrFail($colaId);
        $esMismoDia = Carbon::parse($cita->fecha)->isToday();

        // Reglas de Eliminación: Paciente no puede eliminar si es el mismo día
        if (!$this->esPersonalAutorizado && $esMismoDia) {
            session()->flash('error', 'Los pacientes no pueden cancelar o eliminar citas el mismo día de la atención. Por favor contacte al consultorio.');
            return;
        }

        $cita->delete();
        session()->flash('message', 'La cita ha sido eliminada correctamente.');
        $this->cargarAgenda();
    }

    public function render()
    {
        return view('livewire.components.day-schedule');
    }
}