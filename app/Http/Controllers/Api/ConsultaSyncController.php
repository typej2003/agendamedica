<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Consulta;
use App\Models\Medico;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ConsultaSyncController extends Controller
{
    /**
     * Sincroniza y guarda los registros de consultas enviados desde PowerBuilder.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function sincronizar(Request $request)
    {
        $data = $request->all();

        // Extraer consultas ya sea que vengan en un array o dentro del objeto principal
        $consultas = isset($data['consultas']) ? $data['consultas'] : (isset($data[0]) ? $data : [$data]);

        // Extraer reg_medico de la cabecera o del primer objeto
        $primerRegistro = is_array($data) && isset($data[0]) ? $data[0] : $data;
        $regMedico = $primerRegistro['reg_medico'] ?? $request->input('reg_medico');

        // Búsqueda del médico por reg-medico
        $medico = Medico::where('reg-medico', $regMedico)->first();

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
            foreach ($consultas as $index => $item) {
                if (!isset($item['numhistoria']) || !isset($item['nroconsulta'])) {
                    $errores[] = [
                        'posicion' => $index,
                        'error' => 'El registro no contiene numhistoria o nroconsulta.'
                    ];
                    continue;
                }

                // Crear o actualizar la consulta según numhistoria, nroconsulta y reg-medico
                Consulta::updateOrCreate(
                    [
                        'numhistoria' => $item['numhistoria'],
                        'nroconsulta' => $item['nroconsulta'],
                        'reg-medico'   => $regMedico,
                    ],
                    [
                        'medico_id'           => $medico->id,
                        'fecha'               => $item['fecha'] ?? null,
                        'enfermedadactual'    => $item['enfermedadactual'] ?? null,
                        'peso'                => $item['peso'] ?? null,
                        'talla'               => $item['talla'] ?? null,
                        'fc'                  => $item['fc'] ?? null,
                        'pp'                  => $item['pp'] ?? null,
                        'circcefalica'        => $item['circcefalica'] ?? null,
                        'circtoraxica'        => $item['circtoraxica'] ?? null,
                        'circabdominal'       => $item['circabdominal'] ?? null,
                        'tasentado'           => $item['tasentado'] ?? null,
                        'taacostado'          => $item['taacostado'] ?? null,
                        'tapie'               => $item['tapie'] ?? null,
                        'resultadoexamencomp' => $item['resultadoexamencomp'] ?? null,
                        'eliminado'           => $item['eliminado'] ?? null,
                        'faringe'             => $item['faringe'] ?? null,
                        'nariz'               => $item['nariz'] ?? null,
                        'oido'                => $item['oido'] ?? null,
                        'laringe'             => $item['laringe'] ?? null,
                        'cuello'              => $item['cuello'] ?? null,
                        'otros'               => $item['otros'] ?? null,
                        'evolucion'           => $item['evolucion'] ?? null,
                        'observaciones'       => $item['observaciones'] ?? null,
                        'medico'              => $item['medico'] ?? null,
                        'sms'                 => $item['sms'] ?? null,
                    ]
                );

                $registrosProcesados++;
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Sincronización de consultas finalizada exitosamente.',
                'procesados' => $registrosProcesados,
                'errores' => $errores
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Error sincronizando consultas: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ocurrió un error al sincronizar las consultas.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }
}