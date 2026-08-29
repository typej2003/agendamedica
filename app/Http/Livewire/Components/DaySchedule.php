<?php

namespace App\Http\Livewire\Components;

use Livewire\Component;
use App\Models\Medico;
use App\Models\Cola;
use App\Models\User;
use App\Models\Paciente;
use App\Models\MedicoPaciente;
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
    public $esMedicoPropietario = false;
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
        $this->medico = Medico::find($medicoId) ?? User::find($medicoId);

        /** @var User $user */
        $user = Auth::user();
        if ($user) {
            if ($user->hasRole('Root') || $user->hasRole('Medico') || $user->hasRole('Secretaria')) {
                $this->esPersonalAutorizado = true;
            }

            // Flexibilizar la verificación para determinar si el usuario autenticado es el médico
            if ($user->hasRole('Medico')) {
                if (
                    (isset($user->medico_id) && $user->medico_id == $this->medicoId) ||
                    ($user->id == $this->medicoId) ||
                    ($this->medico && isset($this->medico->user_id) && $this->medico->user_id == $user->id) ||
                    ($this->medico && isset($this->medico->email) && $this->medico->email == $user->email)
                ) {
                    $this->esMedicoPropietario = true;
                }
            }
        }

        $this->cargarAgenda();
    }

    public function cargarAgenda()
    {
        /** @var User $user */
        $user = Auth::user();
        $numHistoriaUser = $user ? ($user->numhistoria ?? $user->id) : null;

        $citas = Cola::where('medico_id', $this->medicoId)
            ->whereDate('fecha', $this->fecha)
            ->orderBy('numorden', 'asc')
            ->get();

        $this->miCitaDelDia = $citas->firstWhere('numhistoria', $numHistoriaUser);

        // Mapear información de los pacientes
        $pacientesData = [];
        
        if ($this->esPersonalAutorizado) {
            $numHistorias = $citas->pluck('numhistoria')->filter()->unique()->toArray();

            if (!empty($numHistorias)) {
                // 1. Obtener la relación desde MedicoPaciente por medico_id y numhistoria
                $relaciones = MedicoPaciente::where('medico_id', $this->medicoId)
                    ->whereIn('numhistoria', $numHistorias)
                    ->get()
                    ->keyBy('numhistoria');

                $pacienteIds = $relaciones->pluck('paciente_id')->unique()->toArray();

                // 2. Consultar únicamente el modelo Paciente mediante los IDs obtenidos o por coincidencia directa de numhistoria
                $pacientes = Paciente::whereIn('id', array_merge($numHistorias, $pacienteIds))->get();

                foreach ($citas as $cita) {
                    $relacion = $relaciones->get($cita->numhistoria);
                    $pacienteIdBuscado = $relacion ? $relacion->paciente_id : $cita->numhistoria;

                    $pacienteObj = $pacientes->firstWhere('id', $pacienteIdBuscado);

                    if ($pacienteObj) {
                        $nombreCompleto = trim(($pacienteObj->nombres ?? '') . ' ' . ($pacienteObj->apellidos ?? ''));
                        $nac = $pacienteObj->nac ? $pacienteObj->nac . '-' : '';
                        $cedula = $nac . ($pacienteObj->cedula ?? 'N/A');

                        $pacientesData[$cita->numhistoria] = [
                            'cedula' => $cedula,
                            'nombre_completo' => !empty($nombreCompleto) ? $nombreCompleto : 'Paciente #' . $pacienteObj->id
                        ];
                    } else {
                        $pacientesData[$cita->numhistoria] = [
                            'cedula' => 'N/A',
                            'nombre_completo' => 'Historia N° ' . $cita->numhistoria
                        ];
                    }
                }
            }
        }

        if ($this->modoAtencion === 'cupos') {
            $this->generarAgendaPorCupos($citas, $pacientesData);
        } else {
            $this->generarAgendaPorHorarios($citas, $pacientesData);
        }
    }

    private function generarAgendaPorCupos($citas, $pacientesData)
    {
        $citasPorOrden = $citas->keyBy('numorden');
        $slots = [];
        $now = Carbon::now();
        $esHoy = Carbon::parse($this->fecha)->isToday();

        for ($i = 1; $i <= $this->maxCupos; $i++) {
            $cita = $citasPorOrden->get($i);
            $ocupada = !is_null($cita);
            $pasado = $esHoy && $now->hour >= 18;

            $citaArray = null;
            if ($cita) {
                $citaArray = $cita->toArray();
                if (isset($pacientesData[$cita->numhistoria])) {
                    $citaArray['paciente_cedula'] = $pacientesData[$cita->numhistoria]['cedula'];
                    $citaArray['paciente_nombre'] = $pacientesData[$cita->numhistoria]['nombre_completo'];
                } else {
                    $citaArray['paciente_cedula'] = 'N/A';
                    $citaArray['paciente_nombre'] = 'N/A';
                }
            }

            $slots[] = [
                'numorden' => $i,
                'hora_ini' => null,
                'display' => "Cupo #{$i}",
                'ocupada' => $ocupada,
                'pasado'  => $pasado,
                'cita'    => $citaArray,
            ];
        }

        $this->itemsSlots = $slots;
    }

    private function generarAgendaPorHorarios($citas, $pacientesData)
    {
        $citasPorHora = $citas->keyBy(function ($item) {
            return $item->hora_ini ? Carbon::parse($item->hora_ini)->format('H:i:s') : null;
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
            $pasado = $esHoy && $inicio->format('H:i') < $now->format('H:i');

            $citaArray = null;
            if ($cita) {
                $citaArray = $cita->toArray();
                if (isset($pacientesData[$cita->numhistoria])) {
                    $citaArray['paciente_cedula'] = $pacientesData[$cita->numhistoria]['cedula'];
                    $citaArray['paciente_nombre'] = $pacientesData[$cita->numhistoria]['nombre_completo'];
                } else {
                    $citaArray['paciente_cedula'] = 'N/A';
                    $citaArray['paciente_nombre'] = 'N/A';
                }
            }

            $slots[] = [
                'numorden' => $orden,
                'hora_ini' => $horaStr,
                'display' => $horaDisplay,
                'ocupada' => $ocupada,
                'pasado'  => $pasado,
                'cita'    => $citaArray,
            ];

            $inicio->addMinutes($this->intervaloMinutos);
            $orden++;
        }

        $this->itemsSlots = $slots;
    }

    public function agendarCupo($numorden, $horaIni = null)
    {
        /** @var User $user */
        $pacienteSinhistoriaId = null;
        $user = Auth::user();
        $paciente = Paciente::where('user_id', $user->id)->first();
        $numHistoriaUser = $paciente ? $paciente->numhistoria : '';
        if($numHistoriaUser === '') {
            $pacienteSinhistoriaId = $paciente->id; // Usar el ID del paciente como referencia para pacientes sin historia
        }

        // Validar que el paciente solo tome UNA cita al mes con este médico
        $startOfMonth = Carbon::parse($this->fecha)->startOfMonth()->toDateString();
        $endOfMonth = Carbon::parse($this->fecha)->endOfMonth()->toDateString();

        $tieneCitaEnMes = Cola::where('medico_id', $this->medicoId)
            ->where('numhistoria', $numHistoriaUser)
            ->whereBetween('fecha', [$startOfMonth, $endOfMonth])
            ->exists();

        if ($tieneCitaEnMes && !$this->esPersonalAutorizado) {
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

        // Cálculo de hora_ini, hora_fin y turno
        if (!empty($horaIni)) {
            $dtHoraIni = Carbon::parse($horaIni);
            $horaIniFinal = $dtHoraIni->format('H:i:s');
            $horaFinCalculada = $dtHoraIni->copy()->addMinutes($this->intervaloMinutos)->format('H:i:s');
            $turno = ($dtHoraIni->hour < 12) ? 'M' : 'T';
        } else {
            $horaIniFinal = '08:00:00';
            $horaFinCalculada = '08:30:00';
            $turno = 'M';
        }

        $regMedico = $this->medico->license_number ?? $this->medico->reg_medico ?? 'REG-000';
        $monto = $this->medico->consultation_fee ?? $this->medico->monto ?? 0.00;

        // Guardar cita en la tabla `cola`
        Cola::create([
            'medico_id'   => $this->medicoId,
            'reg-medico'  => $regMedico,
            'fecha'       => $this->fecha,
            'numhistoria' => $numHistoriaUser,
            'paciente_sinhistoria_id' => $pacienteSinhistoriaId,
            'numorden'    => $numorden,
            'atendido'    => 0,
            'estado'      => 0,
            'turno'       => $turno,
            'motivo'      => 'Consulta Médica Generada por Sistema',
            'monto'       => $monto,
            'hora_ini'    => $horaIniFinal,
            'hora_fin'    => $horaFinCalculada,
            'tiempo'      => $this->intervaloMinutos,
            'tipo'        => 'Cita',
            'medico'      => $this->medicoId,
        ]);

        // Registrar relación en MedicoPaciente
        MedicoPaciente::firstOrCreate([
            'medico_id'   => $this->medicoId,
            'paciente_id' => $user->id,
        ], [
            'numhistoria' => $numHistoriaUser,
            'reg-medico'  => $regMedico,
        ]);

        session()->flash('message', '¡Cita agendada exitosamente!');
        $this->cargarAgenda();
    }

    public function eliminarCita($colaId)
    {
        $cita = Cola::findOrFail($colaId);
        $esMismoDia = Carbon::parse($cita->fecha)->isToday();

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