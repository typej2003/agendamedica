<?php

namespace App\Http\Livewire\Admin;

use App\Models\Historia;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\Paciente;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithFileUploads;

class CargarSql extends Component
{
    use WithFileUploads;

    public $archivosSql = [];
    public $nuevosArchivos = [];
    public $medico_id = '';
    public $regMedicoSeleccionado = '';

    protected $rules = [
        'medico_id' => 'required|exists:medicos,id',
        'archivosSql' => 'required|array|min:1',
        'archivosSql.*' => 'required|file|max:102400', // Máximo 100MB por archivo
    ];

    protected $messages = [
        'medico_id.required' => 'Debe seleccionar un médico.',
        'medico_id.exists' => 'El médico seleccionado no existe en el sistema.',
        'archivosSql.required' => 'Debe adjuntar al menos un archivo SQL.',
        'archivosSql.*.required' => 'Uno de los archivos adjuntados no es válido.',
        'archivosSql.*.max' => 'Los archivos SQL no deben superar los 100MB cada uno.',
    ];

    public function updatedNuevosArchivos()
    {
        $this->validate([
            'nuevosArchivos.*' => 'required|file|max:102400',
        ], [
            'nuevosArchivos.*.max' => 'Los archivos SQL no deben superar los 100MB cada uno.',
        ]);

        foreach ($this->nuevosArchivos as $archivo) {
            $this->archivosSql[] = $archivo;
        }

        $this->reset('nuevosArchivos');
    }

    public function eliminarArchivo($index)
    {
        if (isset($this->archivosSql[$index])) {
            unset($this->archivosSql[$index]);
            $this->archivosSql = array_values($this->archivosSql);
        }
    }

    public function updatedMedicoId($value)
    {
        $this->regMedicoSeleccionado = '';

        if (!empty($value)) {
            $medico = Medico::find($value);
            if ($medico) {
                $medicoRegistro = MedicoRegistro::where('medico_id', $medico->id)->first();

                $this->regMedicoSeleccionado = $medicoRegistro->{'reg_medico'} 
                                            ?? $medicoRegistro->reg_medico 
                                            ?? $medico->{'reg_medico'} 
                                            ?? $medico->reg_medico 
                                            ?? (string)$medico->id;
            }
        }
    }

    public function procesarSql()
    {
        @set_time_limit(0);
        @ini_set('memory_limit', '1024M');

        $this->validate();

        $archivosProcesados = 0;

        try {
            // Desactivar restricciones para facilitar inserción directa
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');

            foreach ($this->archivosSql as $archivo) {
                if (!$archivo || !$archivo->getRealPath() || !file_exists($archivo->getRealPath())) {
                    continue;
                }

                $rutaReal = $archivo->getRealPath();
                $contenidoSql = file_get_contents($rutaReal);

                if (!empty(trim($contenidoSql))) {
                    // Ejecución directa mediante PDO
                    DB::connection()->getPdo()->exec($contenidoSql);
                    $archivosProcesados++;
                }
            }

            // Poblado post-importación desde la tabla pacientes usando reg_medico
            if ($archivosProcesados > 0 && !empty($this->medico_id)) {
                $this->sincronizarDesdePacientes();
            }

            // Reactivar llaves foráneas
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            if ($archivosProcesados === 0) {
                session()->flash('error', 'No se pudieron procesar los archivos seleccionados.');
                return;
            }

            $this->reset(['archivosSql', 'nuevosArchivos', 'medico_id', 'regMedicoSeleccionado']);
            session()->flash('message', "¡Proceso completado con éxito! Se importaron {$archivosProcesados} archivos SQL y se poblaron medico_pacientes e historias correctamente.");

        } catch (\Throwable $e) {
            try {
                DB::statement('SET FOREIGN_KEY_CHECKS=1;');
            } catch (\Throwable $ex) {
                // Ignorar error al restaurar FK
            }

            session()->flash('error', 'Error en la importación SQL: ' . $e->getMessage());
        }
    }

    /**
     * Poblado directo de MedicoPaciente e Historia utilizando el modelo Paciente y reg_medico
     */
    private function sincronizarDesdePacientes()
    {
        $medicoId = $this->medico_id;
        $regMedico = $this->regMedicoSeleccionado;

        // Buscar los pacientes que tengan asignado este reg_medico (o consultar todos si no traen la columna filtrada)
        $pacientes = Paciente::where('reg_medico', $regMedico)
            ->orWhere('reg_medico', $regMedico)
            ->get();

        // En caso de que en la tabla 'pacientes' los registros importados no traigan aún el reg_medico
        if ($pacientes->isEmpty()) {
            $pacientes = Paciente::all();
        }

        foreach ($pacientes as $paciente) {
            $numHistoria = $paciente->numhistoria ?? $paciente->num_historia ?? $paciente->id;

            // 1. Poblar MedicoPaciente
            MedicoPaciente::firstOrCreate(
                [
                    'medico_id'  => $medicoId,
                    'paciente_id' => $paciente->id,
                ],
                [
                    'numhistoria' => $numHistoria,
                    'reg_medico'  => $regMedico,
                ]
            );

            // 2. Poblar Historia con los datos del paciente y médico
            Historia::firstOrCreate(
                [
                    'numhistoria' => $numHistoria,
                    'medico_id'   => $medicoId,
                    'paciente_id' => $paciente->id,
                ],
                [
                    'reg_medico'        => $regMedico,
                    'medical_center_id' => 1,
                ]
            );
        }
    }

    public function render()
    {
        $medicos = Medico::orderBy('name', 'asc')->get()->map(function ($medico) {
            $medicoRegistro = MedicoRegistro::where('medico_id', $medico->id)->first();

            $medico->reg_medico_calculado = $medicoRegistro->{'reg_medico'} 
                                            ?? $medicoRegistro->reg_medico 
                                            ?? $medico->{'reg_medico'} 
                                            ?? $medico->reg_medico 
                                            ?? null;
            return $medico;
        });

        return view('livewire.admin.cargar-sql', [
            'medicos' => $medicos
        ]);
    }
}