<?php

namespace App\Http\Livewire\Admin;

use App\Models\Medico;
use App\Models\MedicoRegistro;
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

                $this->regMedicoSeleccionado = $medicoRegistro->{'reg-medico'} 
                                            ?? $medicoRegistro->reg_medico 
                                            ?? $medico->{'reg-medico'} 
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
        $consultasEjecutadas = 0;

        try {
            // Desactivar restricciones de llaves foráneas y autocommit
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');

            foreach ($this->archivosSql as $archivo) {
                if (!$archivo || !$archivo->getRealPath() || !file_exists($archivo->getRealPath())) {
                    continue;
                }

                $rutaReal = $archivo->getRealPath();
                $handle = fopen($rutaReal, 'r');

                if ($handle) {
                    $sqlBuffer = '';

                    while (($linea = fgets($handle)) !== false) {
                        $lineaLimpia = trim($linea);

                        // Omitir líneas vacías y comentarios simples
                        if (
                            empty($lineaLimpia) ||
                            str_starts_with($lineaLimpia, '--') ||
                            str_starts_with($lineaLimpia, '/*') ||
                            str_starts_with($lineaLimpia, '#')
                        ) {
                            continue;
                        }

                        $sqlBuffer .= $linea;

                        // Ejecutar la sentencia cuando se encuentra el ';' final
                        if (str_ends_with($lineaLimpia, ';')) {
                            DB::unprepared($sqlBuffer);
                            $consultasEjecutadas++;
                            $sqlBuffer = '';
                        }
                    }

                    // Procesar remanente si no terminó en ';'
                    if (!empty(trim($sqlBuffer))) {
                        DB::unprepared($sqlBuffer);
                        $consultasEjecutadas++;
                    }

                    fclose($handle);
                    $archivosProcesados++;
                }
            }

            // Reactivar restricciones de llaves foráneas
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            if ($archivosProcesados === 0) {
                session()->flash('error', 'No se pudieron leer los archivos seleccionados.');
                return;
            }

            $this->reset(['archivosSql', 'nuevosArchivos', 'medico_id', 'regMedicoSeleccionado']);
            session()->flash('message', "¡Proceso completado con éxito! Se procesaron {$archivosProcesados} archivos SQL e insertaron {$consultasEjecutadas} sentencias.");

        } catch (\Throwable $e) {
            try {
                DB::statement('SET FOREIGN_KEY_CHECKS=1;');
            } catch (\Throwable $ex) {
                // Ignorar si falla la reactivación en caso de error
            }

            session()->flash('error', 'Error en la importación SQL: ' . $e->getMessage());
        }
    }

    public function render()
    {
        $medicos = Medico::orderBy('name', 'asc')->get()->map(function ($medico) {
            $medicoRegistro = MedicoRegistro::where('medico_id', $medico->id)->first();

            $medico->reg_medico_calculado = $medicoRegistro->{'reg-medico'} 
                                            ?? $medicoRegistro->reg_medico 
                                            ?? $medico->{'reg-medico'} 
                                            ?? $medico->reg_medico 
                                            ?? null;
            return $medico;
        });

        return view('livewire.admin.cargar-sql', [
            'medicos' => $medicos
        ]);
    }
}