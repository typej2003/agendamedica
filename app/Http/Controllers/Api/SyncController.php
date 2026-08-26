<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

class SyncController extends Controller
{
    public function uploadBatch(Request $request)
    {
        // 1. Validar la clave API
        $apiKey = $request->header('X-API-KEY');
        if ($apiKey !== config('app.sync_api_key', 'MiClaveSecreta123!')) {
            return response()->json(['status' => 'error', 'message' => 'API Key inválida.'], 401);
        }

        $tableName = strtolower($request->input('table'));
        $rows = $request->input('data');

        // 2. Validar parámetros recibidos con Log de diagnóstico para HTTP 400
        if (!$tableName || !is_array($rows)) {
            Log::channel('single')->error("Error 400 Bad Request en SyncController", [
                'table_param' => $request->input('table'),
                'data_is_array' => is_array($rows),
                'json_last_error' => json_last_error_msg(),
                'raw_body_preview' => substr($request->getContent(), 0, 500)
            ]);

            return response()->json([
                'status' => 'error', 
                'message' => 'Parámetros inválidos.'
            ], 400);
        }

        if (!Schema::hasTable($tableName)) {
            return response()->json(['status' => 'error', 'message' => "La tabla '{$tableName}' no existe."], 404);
        }

        if (empty($rows)) {
            return response()->json(['status' => 'success', 'message' => 'Lote vacío procesado.']);
        }

        // 3. Insertar el lote en la base de datos
        DB::beginTransaction();
        try {
            $cleanRows = array_map(function ($row) {
                unset($row['id']); // Remover ID local para dejar que MySQL genere el AUTO_INCREMENT
                return $row;
            }, $rows);

            DB::table($tableName)->insert($cleanRows);

            DB::commit();
            return response()->json([
                'status'   => 'success',
                'inserted' => count($cleanRows)
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error sincronizando {$tableName}: " . $e->getMessage());
            return response()->json([
                'status'  => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}