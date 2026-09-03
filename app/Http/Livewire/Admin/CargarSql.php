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
        'archivosSql.*' => 'required|max:102400', // Máximo 100MB por archivo
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
            'nuevosArchivos.*' => 'required|max:102400',
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
        @ini_set('memory_limit', '512M');

        $this->validate();

        $medico = Medico::with('office')->find($this->medico_id);

        if (!$medico) {
            session()->flash('error', 'El médico seleccionado no fue encontrado.');
            return;
        }

        $medicoRegistro = MedicoRegistro::where('medico_id', $medico->id)->first();

        $regMedicoVal = $medicoRegistro->{'reg-medico'} 
                        ?? $medicoRegistro->reg_medico 
                        ?? $medico->{'reg-medico'} 
                        ?? $medico->reg_medico 
                        ?? (string)$medico->id;

        try {
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');

            foreach ($this->archivosSql as $archivo) {
                $rutaReal = $archivo->getRealPath();

                if (!file_exists($rutaReal)) {
                    continue;
                }

                $handle = fopen($rutaReal, 'r');
                if ($handle) {
                    $sqlBuffer = '';

                    while (($linea = fgets($handle)) !== false) {
                        $lineaLimpia = trim($linea);

                        if (
                            empty($lineaLimpia) ||
                            str_starts_with($lineaLimpia, '--') ||
                            str_starts_with($lineaLimpia, '/*') ||
                            str_starts_with($lineaLimpia, '#')
                        ) {
                            continue;
                        }

                        $sqlBuffer .= $linea . "\n";

                        if (str_ends_with($lineaLimpia, ';')) {
                            $sqlEjecutar = $this->reemplazarDatosMedico($sqlBuffer, $regMedicoVal, $medico->id, $medico->user_id);

                            if (!empty(trim($sqlEjecutar))) {
                                DB::unprepared($sqlEjecutar);
                            }

                            $sqlBuffer = '';
                        }
                    }

                    if (!empty(trim($sqlBuffer))) {
                        $sqlEjecutar = $this->reemplazarDatosMedico($sqlBuffer, $regMedicoVal, $medico->id, $medico->user_id);
                        DB::unprepared($sqlEjecutar);
                    }

                    fclose($handle);
                }
            }

            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            $this->reset(['archivosSql', 'nuevosArchivos', 'medico_id', 'regMedicoSeleccionado']);
            session()->flash('message', '¡Los archivos SQL fueron procesados e integrados exitosamente con el médico seleccionado!');

        } catch (\Throwable $e) {
            try {
                DB::statement('SET FOREIGN_KEY_CHECKS=1;');
            } catch (\Throwable $ex) {
                // Ignorar si falla el reset de llaves foráneas en el catch
            }

            $mensajeError = !empty($e->getMessage()) ? $e->getMessage() : 'Error desconocido de la base de datos o codificación.';
            session()->flash('error', $mensajeError);
        }
    }

    private function reemplazarDatosMedico(string $sql, string $regMedicoVal, int $medicoId, ?int $userId): string
    {
        $replacements = [
            ':reg_medico' => $regMedicoVal,
            ':reg-medico' => $regMedicoVal,
            ':medico_id'   => $medicoId,
            ':user_id'     => $userId ?? 'NULL',
        ];
        $sql = str_replace(array_keys($replacements), array_values($replacements), $sql);

        if (preg_match('/INSERT\s+INTO\s+[`\w`\.]+\s*\(([^)]+)\)\s*VALUES/i', $sql, $matches)) {
            $columnas = array_map(fn($col) => trim($col, " `\t\n\r\0\x0B"), explode(',', $matches[1]));
            $posicionRegMedico = array_search('reg_medico', $columnas);

            if ($posicionRegMedico !== false) {
                $sql = preg_replace_callback(
                    '/\(([^)]+)\)/s',
                    function ($tuplaMatch) use ($posicionRegMedico, $regMedicoVal) {
                        $valores = explode(',', $tuplaMatch[1]);
                        
                        if (isset($valores[$posicionRegMedico])) {
                            $valores[$posicionRegMedico] = " '" . addslashes($regMedicoVal) . "'";
                        }

                        return '(' . implode(',', $valores) . ')';
                    },
                    $sql
                );
            }
        }

        return $sql;
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