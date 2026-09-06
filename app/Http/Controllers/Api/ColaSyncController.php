<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cola;
use App\Models\Medico;
use App\Models\UploadServer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ColaSyncController extends Controller
{
    /**
     * Sincroniza y guarda únicamente los registros de la cola que no existan previamente.
     * Si la petición indica 'es_ultimo_lote', registra el resguardo en UploadServer.
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

        $registrosNuevos = 0;
        $registrosOmitidos = 0;
        $guardados = [];
        $errores = [];

        DB::beginTransaction();

        try {
            foreach ($colaItems as $index => $item) {
                // Validación básica de campos requeridos
                if (!isset($item['fecha']) || (!isset($item['numhistoria']) && !isset($item['paciente_sinhistoria_id']))) {
                    $errores[] = [
                        'posicion' => $index,
                        'error'    => 'El registro no contiene fecha ni identificador de paciente.'
                    ];
                    continue;
                }

                $horaIni  = $item['hora_ini'] ?? '00:00:00';
                $atendido = $item['atendido'] ?? 0;
                $numHistoria = $item['numhistoria'] ?? null;

                // Verificar la existencia exacta por: fecha, hora_ini, numhistoria y atendido
                $existeRegistro = Cola::where('reg_medico', $regMedico)
                    ->where('fecha', $item['fecha'])
                    ->where('hora_ini', $horaIni)
                    ->where('numhistoria', $numHistoria)
                    ->where('atendido', $atendido)
                    ->exists();

                // Si ya existe, se omite y no se vuelve a insertar
                if ($existeRegistro) {
                    $registrosOmitidos++;
                    continue;
                }

                // Si no existe, se registra como nuevo item en la tabla
                $nuevaCola = Cola::create([
                    'reg_medico'              => $regMedico,
                    'fecha'                   => $item['fecha'],
                    'numhistoria'             => $numHistoria,
                    'paciente_sinhistoria_id' => $item['paciente_sinhistoria_id'] ?? null,
                    'numorden'                => $item['numorden'] ?? null,
                    'atendido'                => $atendido,
                    'estado'                  => $item['estado'] ?? null,
                    'turno'                   => $item['turno'] ?? null,
                    'motivo'                  => $item['motivo'] ?? null,
                    'monto'                   => $item['monto'] ?? null,
                    'hora_ini'                => $horaIni,
                    'hora_fin'                => $item['hora_fin'] ?? null,
                    'tiempo'                  => $item['tiempo'] ?? null,
                    'tipo'                    => $item['tipo'] ?? null,
                    'conse'                   => $item['conse'] ?? null,
                    'sms'                     => $item['sms'] ?? null,
                    'sms_text'                => $item['sms_text'] ?? null,
                    'medico'                  => $item['medico'] ?? null,
                ]);

                // Guardar la información relevante del registro creado para armar el payload
                $guardados[] = [
                    'id'          => $nuevaCola->id,
                    'fecha'       => $nuevaCola->fecha,
                    'numhistoria' => $nuevaCola->numhistoria,
                    'hora_ini'    => $nuevaCola->hora_ini,
                    'atendido'    => $nuevaCola->atendido
                ];

                $registrosNuevos++;
            }

            // Evaluar si es el último lote enviado por PowerBuilder para guardar en UploadServer
            $esUltimoLote = $request->boolean('es_ultimo_lote', false);

            if ($esUltimoLote) {
                $ultimoGuardado = end($guardados);

                UploadServer::create([
                    'entity_type'           => 'cola',
                    'batch_type'            => 'cola',
                    'records_count'         => $registrosNuevos,
                    'last_record_id'        => $ultimoGuardado ? (string)$ultimoGuardado['id'] : null,
                    'last_record_timestamp' => now(),
                    'status'                => 'completed',
                    'payload'               => $guardados, // Registros efectivamente creados en la entrega final
                ]);
            }

            DB::commit();

            return response()->json([
                'success'             => true,
                'message'             => 'Sincronización de la cola realizada con éxito.',
                'es_ultimo_lote'      => $esUltimoLote,
                'insertados'          => $registrosNuevos,
                'omitidos'            => $registrosOmitidos,
                'registros_guardados' => $guardados,
                'errores'             => $errores
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