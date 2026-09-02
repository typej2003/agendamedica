<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
        $regMedico = $request->input('reg-medico') ?? $request->input('reg_medico');

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

        // Sanitización para evitar inyección SQL en nombres de tablas
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $tableName) || !Schema::hasTable($tableName)) {
            return response()->json(['status' => 'error', 'message' => "La tabla '{$tableName}' no existe o no es válida."], 404);
        }

        if (empty($rows)) {
            return response()->json(['status' => 'success', 'message' => 'Lote vacío procesado.']);
        }

        // 3. Evaluar la inclusión del parámetro reg-medico
        // Se excluye para las tablas 'paciente' y 'pacientes'
        $excludeRegMedicoTables = ['paciente', 'pacientes'];
        $shouldIncludeRegMedico = !in_array($tableName, $excludeRegMedicoTables, true) && !is_null($regMedico);

        // Obtenemos las columnas reales de la tabla para prevenir erogaciones por campos inexistentes
        $tableColumns = Schema::getColumnListing($tableName);

        // 4. Transformación y limpieza de registros
        $cleanRows = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            // Remover ID local para dejar que el autoincremento actúe
            unset($row['id']);

            // Agregar reg_medico solo si aplica y si la columna existe en la tabla destino
            if ($shouldIncludeRegMedico && in_array('reg_medico', $tableColumns, true)) {
                $row['reg_medico'] = $regMedico;
            } elseif ($shouldIncludeRegMedico && in_array('reg-medico', $tableColumns, true)) {
                $row['reg-medico'] = $regMedico;
            }

            $cleanRows[] = $row;
        }

        if (empty($cleanRows)) {
            return response()->json(['status' => 'error', 'message' => 'No hay registros válidos para insertar.'], 400);
        }

        // 5. Insertar el lote dentro de una transacción
        DB::beginTransaction();
        try {
            // Se realiza la inserción en bloques para evitar exceder el límite de placeholders de la BD
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
            Log::error("Error sincronizando {$tableName}: " . $e->getMessage(), [
                'exception' => $e
            ]);

            return response()->json([
                'status'  => 'error',
                'message' => 'Error al procesar la inserción en la base de datos.',
                'debug'   => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }
}