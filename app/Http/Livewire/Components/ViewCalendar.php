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
        Carbon::setLocale('es');

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
        Carbon::setLocale('es');
        $this->currentDate = Carbon::parse($this->currentDate)->addMonth()->startOfMonth();
        $this->buildCalendar();
    }

    private function buildCalendar()
    {
        Carbon::setLocale('es');
        $today = Carbon::today();
        $startOfMonth = Carbon::parse($this->currentDate)->startOfMonth();
        $endOfMonth = Carbon::parse($this->currentDate)->endOfMonth();

        $citas = Cola::where('medico_id', $this->medicoId)
            ->whereBetween('fecha', [$startOfMonth->toDateString(), $endOfMonth->toDateString()])
            ->selectRaw('DATE(fecha) as fecha_corta, COUNT(*) as total')
            ->groupBy('fecha_corta')
            ->pluck('total', 'fecha_corta')
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
                $isPastDay = $currentDay->lt($today);

                $bgColor = 'bg-white';
                $badgeColor = 'bg-light text-dark border';

                if ($isPastDay) {
                    $bgColor = 'bg-light text-muted opacity-50';
                } elseif ($count >= $this->maxPacientesPorDia) {
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
                    'isPastDay' => $isPastDay,
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