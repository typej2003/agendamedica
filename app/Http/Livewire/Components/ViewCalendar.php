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

    public function previousMonth()
    {
        $this->currentDate = Carbon::parse($this->currentDate)->subMonth()->startOfMonth();
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

        // Obtener la cantidad de citas por día agrupadas para el médico en el mes actual
        $citas = Cola::where('medico_id', $this->medicoId)
            ->whereBetween('fecha', [$startOfMonth->toDateString(), $endOfMonth->toDateString()])
            ->selectRaw('fecha, COUNT(*) as total')
            ->groupBy('fecha')
            ->pluck('total', 'fecha')
            ->toArray();

        $this->citasPorDia = $citas;

        // Construir la matriz del calendario (semanas y días)
        $startDayOfWeek = $startOfMonth->copy()->startOfWeek(Carbon::MONDAY);
        $endDayOfWeek = $endOfMonth->copy()->endOfWeek(Carbon::SUNDAY);

        $calendar = [];
        $currentDay = $startDayOfWeek->copy();

        while ($currentDay <= $endDayOfWeek) {
            $week = [];
            for ($i = 0; $i < 7; $i++) {
                $dateString = $currentDay->format('Y-m-d');
                $week[] = [
                    'date' => $currentDay->copy(),
                    'isCurrentMonth' => $currentDay->month === $startOfMonth->month,
                    'isToday' => $currentDay->isToday(),
                    'citasCount' => $this->citasPorDia[$dateString] ?? 0,
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