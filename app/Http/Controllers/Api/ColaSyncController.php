<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cola;
use App\Models\Medico;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ColaSyncController extends Controller
{
    /**
     * Sincroniza y guarda los registros de la cola enviados desde PowerBuilder.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function sincronizar(Request $request)
    {
        $data = $request->all();

        // Extraer cola ya sea que vengan en un array o dentro del objeto principal
        $colaItems = isset($data['cola']) ? $data['cola'] : (isset($data[0]) ? $data : [$data]);

        // Extraer reg_medico de la cabecera o del primer objeto
        $primerRegistro = is_array($data) && isset($data[0]) ? $data[0] : $data;
        $regMedico = $primerRegistro['reg_medico'] ?? $request->input('reg_medico');

        // Búsqueda del médico por reg_medico
        $medico = Medico::where('reg_medico', $regMedico)->first();

        if (!$medico) {
            return response()->json([
                'success' => false,
                'message' => 'No se encontraron las referencias requeridas.',
                'errores' => [
                    'medico' => 'Médico no encontrado con reg_medico: ' . $regMedico,
                ]
            ], 404);
        }

        $registrosProcesados = 0;
        $errores = [];

        DB::beginTransaction();

        try {
            foreach ($colaItems as $index => $item) {
                // Validación básica de campos requeridos por la migración y lógica de negocio
                if (!isset($item['fecha']) || (!isset($item['numhistoria']) && !isset($item['paciente_sinhistoria_id']))) {
                    $errores[] = [
                        'posicion' => $index,
                        'error' => 'El registro no contiene fecha ni identificador de paciente (numhistoria o paciente_sinhistoria_id).'
                    ];
                    continue;
                }

                // Crear o actualizar la entrada en la cola
                Cola::updateOrCreate(
                    [
                        'reg_medico'              => $regMedico,
                        'fecha'                   => $item['fecha'],
                        'numhistoria'             => $item['numhistoria'] ?? null,
                        'paciente_sinhistoria_id' => $item['paciente_sinhistoria_id'] ?? null,
                    ],
                    [
                        'medico_id'               => $medico->id,
                        'numorden'                => $item['numorden'] ?? null,
                        'atendido'                => $item['atendido'] ?? null,
                        'estado'                  => $item['estado'] ?? null,
                        'turno'                   => $item['turno'] ?? null,
                        'motivo'                  => $item['motivo'] ?? null,
                        'monto'                   => $item['monto'] ?? null,
                        'hora_ini'                => $item['hora_ini'] ?? '00:00:00',
                        'hora_fin'                => $item['hora_fin'] ?? null,
                        'tiempo'                  => $item['tiempo'] ?? null,
                        'tipo'                    => $item['tipo'] ?? null,
                        'conse'                   => $item['conse'] ?? null,
                        'sms'                     => $item['sms'] ?? null,
                        'sms_text'                => $item['sms_text'] ?? null,
                        'medico'                  => $item['medico'] ?? null,
                    ]
                );

                $registrosProcesados++;
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Sincronización de la cola finalizada exitosamente.',
                'procesados' => $registrosProcesados,
                'errores' => $errores
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Error sincronizando cola: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ocurrió un error al sincronizar la cola.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }
}