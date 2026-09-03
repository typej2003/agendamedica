<?php

namespace App\Http\Livewire\Admin;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithFileUploads;

class CargarSql extends Component
{
    use WithFileUploads;

    public $archivosSql = [];
    public $medico_id = '';
    public $procesando = false;

    protected $rules = [
        'medico_id' => 'required|exists:users,id',
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

        $medico = User::find($this->medico_id);

        if (!$medico) {
            session()->flash('error', 'El médico seleccionado no fue encontrado.');
            return;
        }

        // Obtener identificadores del médico para reemplazo
        $regMedico = $medico->reg_medico ?? $medico->id;
        $userId = $medico->id;

        DB::beginTransaction();

        try {
            // Desactivar temporalmente revisión de llaves foráneas para evitar conflictos de orden al insertar
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');

            foreach ($this->archivosSql as $archivo) {
                $rutaReal = $archivo->getRealPath();

                if (!file_exists($rutaReal)) {
                    continue;
                }

                // Procesamiento por lectura de stream en buffer para optimizar memoria en archivos pesados
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

                        // Cuando se encuentra el final de una sentencia SQL (;)
                        if (str_ends_with($lineaLimpia, ';')) {
                            // Reemplazos clave de información del médico
                            $sqlEjecutar = $this->reemplazarDatosMedico($sqlBuffer, $regMedico, $userId);

                            if (!empty(trim($sqlEjecutar))) {
                                DB::unprepared($sqlEjecutar);
                            }

                            $sqlBuffer = '';
                        }
                    }

                    // En caso de quedar alguna consulta residual sin punto y coma final
                    if (!empty(trim($sqlBuffer))) {
                        $sqlEjecutar = $this->reemplazarDatosMedico($sqlBuffer, $regMedico, $userId);
                        DB::unprepared($sqlEjecutar);
                    }

                    fclose($handle);
                }
            }

            // Volver a activar la verificación de llaves foráneas
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            DB::commit();

            // Limpiar formulario tras éxito
            $this->reset(['archivosSql', 'medico_id']);
            session()->flash('message', '¡Todos los archivos SQL fueron procesados y los datos del médico fueron actualizados exitosamente!');

        } catch (\Exception $e) {
            DB::rollBack();
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
            session()->flash('error', 'Ocurrió un error durante la ejecución del SQL: ' . $e->getMessage());
        }
    }

    /**
     * Reemplaza el registro médico o id de usuario en las consultas
     */
    private function reemplazarDatosMedico(string $sql, string $regMedico, int $userId): string
    {
        // Reemplaza patrones de registro médico entre comillas si vienen vacíos o con valores genéricos
        $sql = preg_replace("/'([A-Za-z0-9_ -]*)',/i", "'{$regMedico}',", $sql, 1);
        
        // Si tienes campos específicos de relación como user_id o reg_medico explícitos
        $sql = str_replace([':reg_medico', ':user_id'], [$regMedico, $userId], $sql);

        return $sql;
    }

    public function render()
    {
        // Se asume la presencia de médicos según la estructura del sistema
        $medicos = User::orderBy('name', 'asc')->get();

        return view('livewire.admin.cargar-sql', [
            'medicos' => $medicos
        ]);
    }
}