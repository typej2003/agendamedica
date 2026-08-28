<?php

namespace App\Http\Livewire\Components;

use Livewire\Component;
use App\Models\Medico;
use App\Models\MedicalCenter;
use App\Models\Cola;
use Carbon\Carbon;

class ViewCalendar extends Component
{
    public $medicoId;
    public $medicalCenterId;
    public $medico;
    public $medicalCenter;
    
    public $currentDate;
    public $calendarWeeks = [];
    public $citasPorDia = [];

    // Configuración de Topes y Capacidad por día
    public $maxPacientesPorDia = 20; // Límite tope por día
    public $alertaFaltan = 3;       // Umbral para mostrar Amarillo (Faltan N o menos para el tope)

    public function mount($medicoId, $medicalCenterId = null)
    {
        $this->medicoId = $medicoId;
        $this->medicalCenterId = $medicalCenterId;

        $this->medico = Medico::findOrFail($medicoId);
        if ($medicalCenterId) {
            $this->medicalCenter = MedicalCenter::find($medicalCenterId);
        }

        $this->currentDate = Carbon::now()->startOfMonth();
        $this->buildCalendar();
    }

    public function nextMonth()
    {
        $this->currentDate = Carbon::parse($this->currentDate)->addMonth()->startOfMonth();
        $this->buildCalendar();
    }

    private function buildCalendar()
    {
        $startOfMonth = Carbon::parse($this->currentDate)->startOfMonth();
        $endOfMonth = Carbon::parse($this->currentDate)->endOfMonth();

        $citas = Cola::where('medico_id', $this->medicoId)
            ->whereBetween('fecha', [$startOfMonth->toDateString(), $endOfMonth->toDateString()])
            ->selectRaw('fecha, COUNT(*) as total')
            ->groupBy('fecha')
            ->pluck('total', 'fecha')
            ->toArray();

        $this->citasPorDia = $citas;

        $startDayOfWeek = $startOfMonth->copy()->startOfWeek(Carbon::MONDAY);
        $endDayOfWeek = $endOfMonth->copy()->endOfWeek(Carbon::SUNDAY);

        $calendar = [];
        $currentDay = $startDayOfWeek->copy();

        while ($currentDay <= $endDayOfWeek) {
            $week = [];
            for ($i = 0; $i < 7; $i++) {
                $dateString = $currentDay->format('Y-m-d');
                $count = $this->citasPorDia[$dateString] ?? 0;

                // Definición de Colores según requerimiento:
                // Sin pacientes: fondo blanco, badge cero
                // Con pacientes: amarillo (si faltan 3 o menos del tope), rojo (si está completo), blanco (si tiene pacientes pero aún no llega a la alerta)
                $bgColor = 'bg-white';
                $badgeColor = 'bg-light text-dark border';

                if ($count >= $this->maxPacientesPorDia) {
                    $bgColor = 'bg-danger bg-opacity-25'; // Rojo (Completo)
                    $badgeColor = 'bg-danger text-white';
                } elseif ($count >= ($this->maxPacientesPorDia - $this->alertaFaltan) && $count > 0) {
                    $bgColor = 'bg-warning bg-opacity-25'; // Amarillo (Falta 3 o menos del tope)
                    $badgeColor = 'bg-warning text-dark';
                } elseif ($count > 0) {
                    $bgColor = 'bg-white'; // Con pacientes
                    $badgeColor = 'bg-primary text-white';
                }

                $week[] = [
                    'date' => $currentDay->copy(),
                    'dateString' => $dateString,
                    'isCurrentMonth' => $currentDay->month === $startOfMonth->month,
                    'isToday' => $currentDay->isToday(),
                    'citasCount' => $count,
                    'bgColor' => $bgColor,
                    'badgeColor' => $badgeColor,
                ];
                $currentDay->addDay();
            }
            $calendar[] = $week;
        }

        $this->calendarWeeks = $calendar;
    }

    public function render()
    {
        return view('livewire.components.view-calendar');
    }
}