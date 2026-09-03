<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Paciente;
use App\Models\Historia;
use App\Models\MedicoRegistro;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;
use Exception;

class SyncController extends Controller
{
    public function uploadBatch(Request $request)
    {
        // 1. Validar la clave API
        $apiKey = $request->header('X-API-KEY');
        if ($apiKey !== config('app.sync_api_key', 'MiClaveSecreta123!')) {
            return response()->json(['status' => 'error', 'message' => 'API Key inválida.'], 401);
        }

        $tableName = strtolower((string) $request->input('table'));
        $rows = $request->input('data');
        $regMedico = $request->input('reg_medico') ?? $request->input('reg_medico');

        // 2. Validar parámetros recibidos con Log de diagnóstico
        if (empty($tableName) || !is_array($rows)) {
            Log::channel('single')->error("Error 400 Bad Request en SyncController", [
                'table_param'      => $request->input('table'),
                'data_is_array'    => is_array($rows),
                'json_last_error'  => json_last_error_msg(),
                'raw_body_preview' => substr($request->getContent(), 0, 500)
            ]);

            return response()->json([
                'status'  => 'error', 
                'message' => 'Parámetros inválidos.'
            ], 400);
        }

        if (empty($rows)) {
            return response()->json(['status' => 'success', 'message' => 'Lote vacío procesado.']);
        }

        // =========================================================================
        // TRATAMIENTO ESPECIAL: TABLA PACIENTE / PACIENTES
        // =========================================================================
        if (in_array($tableName, ['paciente', 'pacientes'], true)) {
            // Si el reg_medico no viene en la raíz, intentamos tomarlo del primer ítem del lote
            if (empty($regMedico) && isset($rows[0]) && is_array($rows[0])) {
                $regMedico = $rows[0]['reg_medico'] ?? $rows[0]['reg_medico'] ?? null;
            }

            // Buscar medico_id utilizando el modelo MedicoRegistro
            $medicoRegistro = MedicoRegistro::where('reg_medico', $regMedico)
                ->orWhere('reg_medico', $regMedico)
                ->first();

            if (!$medicoRegistro) {
                return response()->json([
                    'status'  => 'error',
                    'message' => 'Médico no encontrado para la sincronización de pacientes.',
                    'errors'  => ['reg_medico' => 'Registro no encontrado con reg_medico: ' . $regMedico]
                ], 404);
            }

            $medicoId = $medicoRegistro->medico_id;
            $registrosProcesados = 0;
            $errores = [];

            DB::beginTransaction();
            try {
                foreach ($rows as $index => $item) {
                    if (!is_array($item) || !isset($item['cedula'])) {
                        $errores[] = [
                            'posicion' => $index,
                            'error'    => 'El registro no contiene un identificador válido (cedula).'
                        ];
                        continue;
                    }

                    // 1. Crear o actualizar la ficha general del paciente por cédula
                    $paciente = Paciente::updateOrCreate(
                        ['cedula' => $item['cedula']],
                        [
                            'nac'         => $item['nac'] ?? null,
                            'apellidos'   => $item['apellidos'] ?? null,
                            'nombres'     => $item['nombres'] ?? null,
                            'sexo'        => $item['sexo'] ?? null,
                            'fnacimiento' => $item['fnacimiento'] ?? null,
                            'lnacimiento' => $item['lnacimiento'] ?? null,
                            'codeestado'  => $item['codeestado'] ?? null,
                            'direccion'   => $item['direccion'] ?? null,
                            'telefono'    => $item['telefono'] ?? null,
                            'fingreso'    => $item['fingreso'] ?? null,
                            'escolaridad' => $item['escolaridad'] ?? null,
                            'ocupacion'   => $item['ocupacion'] ?? null,
                            'codesegemp'  => $item['codesegemp'] ?? null,
                            'foto_pac'    => $item['foto_pac'] ?? null,
                            'profesion'   => $item['profesion'] ?? null,
                            'email'       => $item['email'] ?? null,
                            'dependencia' => $item['dependencia'] ?? null,
                            'medico'      => $item['medico'] ?? null,
                            'sms'         => $item['sms'] ?? null,
                        ]
                    );

                    $numHistoriaPowerBuilder = $item['numhistoria'] ?? null;

                    // 2. Asociar paciente con el médico en la tabla pivote (si la relación Eloquent existe en Paciente)
                    if (method_exists($paciente, 'medicos')) {
                        $paciente->medicos()->syncWithoutDetaching([
                            $medicoId => [
                                'numhistoria' => $numHistoriaPowerBuilder,
                                'reg_medico'  => $regMedico,
                            ]
                        ]);
                    }

                    // 3. Crear o actualizar la Historia del paciente
                    Historia::updateOrCreate(
                        [
                            'paciente_id' => $paciente->id,
                            'medico_id'   => $medicoId,
                        ],
                        [
                            'numhistoria'       => $numHistoriaPowerBuilder,
                            'reg_medico'        => $regMedico,
                            'medical_center_id' => null,
                        ]
                    );

                    $registrosProcesados++;
                }

                DB::commit();

                return response()->json([
                    'status'     => 'success',
                    'inserted'   => $registrosProcesados,
                    'errores'    => $errores
                ], 200);

            } catch (Exception $e) {
                DB::rollBack();
                Log::error("Error sincronizando pacientes: " . $e->getMessage(), ['exception' => $e]);

                return response()->json([
                    'status'  => 'error',
                    'message' => 'Error al procesar la inserción de pacientes.',
                    'debug'   => config('app.debug') ? $e->getMessage() : null
                ], 500);
            }
        }

        // =========================================================================
        // TRATAMIENTO GENERAL: RESTO DE TABLAS
        // =========================================================================
        
        // Sanitización para evitar inyección SQL en nombres de tablas
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $tableName) || !Schema::hasTable($tableName)) {
            return response()->json(['status' => 'error', 'message' => "La tabla '{$tableName}' no existe o no es válida."], 404);
        }

        $tableColumns = Schema::getColumnListing($tableName);

        $cleanRows = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            // Remover ID local para autoincremento
            unset($row['id']);

            // Inyectar reg_medico solo si la columna existe en la tabla destino
            if (!is_null($regMedico)) {
                if (in_array('reg_medico', $tableColumns, true)) {
                    $row['reg_medico'] = $regMedico;
                } elseif (in_array('reg_medico', $tableColumns, true)) {
                    $row['reg_medico'] = $regMedico;
                }
            }

            $cleanRows[] = $row;
        }

        if (empty($cleanRows)) {
            return response()->json(['status' => 'error', 'message' => 'No hay registros válidos para insertar.'], 400);
        }

        DB::beginTransaction();
        try {
            $chunks = array_chunk($cleanRows, 500);
            foreach ($chunks as $chunk) {
                DB::table($tableName)->insert($chunk);
            }

            DB::commit();

            return response()->json([
                'status'   => 'success',
                'inserted' => count($cleanRows)
            ], 200);

        } catch (Exception $e) {
            DB::rollBack();
            Log::error("Error sincronizando {$tableName}: " . $e->getMessage(), ['exception' => $e]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Error al procesar la inserción en la base de datos.',
                'debug'   => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }
}