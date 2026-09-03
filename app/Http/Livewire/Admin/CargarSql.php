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
    public $medico_id = '';

    protected $rules = [
        'medico_id' => 'required|exists:medicos,id',
        'archivosSql' => 'required|array|min:1',
        'archivosSql.*' => 'required|file|max:51200', // Máximo 50MB por archivo SQL
    ];

    protected $messages = [
        'medico_id.required' => 'Debe seleccionar un médico.',
        'medico_id.exists' => 'El médico seleccionado no existe en el sistema.',
        'archivosSql.required' => 'Debe adjuntar al menos un archivo SQL.',
        'archivosSql.*.file' => 'Uno de los archivos adjuntados no es válido.',
        'archivosSql.*.max' => 'Los archivos SQL no deben superar los 50MB cada uno.',
    ];

    public function procesarSql()
    {
        $this->validate();

        $medico = Medico::with('office')->find($this->medico_id);

        if (!$medico) {
            session()->flash('error', 'El médico seleccionado no fue encontrado.');
            return;
        }

        // 1. Obtener el registro médico desde MedicoRegistro
        $medicoRegistro = MedicoRegistro::where('medico_id', $medico->id)->first();
        
        // Prioridad: 1. Tabla MedicoRegistro | 2. Campo directo en Medico | 3. ID de usuario
        $regMedicoVal = $medicoRegistro->{'reg-medico'} 
                        ?? $medico->{'reg-medico'} 
                        ?? '';

        DB::beginTransaction();

        try {
            // Desactivar temporalmente revisión de llaves foráneas
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

                        // Omitir comentarios de SQL
                        if (
                            empty($lineaLimpia) ||
                            str_starts_with($lineaLimpia, '--') ||
                            str_starts_with($lineaLimpia, '/*') ||
                            str_starts_with($lineaLimpia, '#')
                        ) {
                            continue;
                        }

                        $sqlBuffer .= $linea . "\n";

                        // Cuando finaliza la sentencia SQL (;)
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

            // Reactivar verificación de llaves foráneas
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            DB::commit();

            $this->reset(['archivosSql', 'medico_id']);
            session()->flash('message', '¡Los archivos SQL fueron procesados e integrados exitosamente con el médico seleccionado!');

        } catch (\Exception $e) {
            DB::rollBack();
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
            session()->flash('error', 'Ocurrió un error al procesar las consultas SQL: ' . $e->getMessage());
        }
    }

    /**
     * Reemplaza el registro médico, ID de médico e ID de usuario dentro de las sentencias SQL.
     */
    private function reemplazarDatosMedico(string $sql, string $regMedicoVal, int $medicoId, ?int $userId): string
    {
        // Reemplazar la primera ocurrencia del valor del registro médico en las tuplas ( 'VALOR', ...
        if (!empty($regMedicoVal)) {
            $sql = preg_replace("/'\s*([A-Za-z0-9_ -]*)\s*',/i", "'{$regMedicoVal}',", $sql, 1);
        }

        // Reemplazar marcadores dinámicos o columnas clave
        $replacements = [
            ':reg_medico' => $regMedicoVal,
            ':reg-medico' => $regMedicoVal,
            ':medico_id'   => $medicoId,
            ':user_id'     => $userId ?? 'NULL',
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $sql);
    }

    public function render()
    {
        $medicos = Medico::orderBy('name', 'asc')->get();

        return view('livewire.admin.cargar-sql', [
            'medicos' => $medicos
        ]);
    }
}