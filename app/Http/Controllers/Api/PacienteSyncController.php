<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Paciente;
use App\Models\Medico;
use App\Models\Historia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PacienteSyncController extends Controller
{
    /**
     * Sincroniza y guarda los registros de pacientes enviados desde PowerBuilder.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function sincronizar(Request $request)
    {
        $data = $request->all();

        // Extraer pacientes ya sea que vengan en un array o como objeto único
        $pacientes = isset($data['pacientes']) ? $data['pacientes'] : (isset($data[0]) ? $data : [$data]);

        // Tomar referencias del request o del primer objeto del payload
        $primerRegistro = is_array($data) && isset($data[0]) ? $data[0] : $data;
        $regMedico = $primerRegistro['reg_medico'] ?? $request->input('reg_medico');

        // Búsqueda del médico por reg_medico
        $medico = Medico::where('reg_medico', $regMedico)->first();

        // Validar existencia del médico
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
            foreach ($pacientes as $index => $item) {
                if (!isset($item['cedula'])) {
                    $errores[] = [
                        'posicion' => $index,
                        'error' => 'El registro no contiene identificador (cedula).'
                    ];
                    continue;
                }

                // 1. Crear o actualizar la ficha general del paciente por cédula (sin numhistoria)
                $paciente = Paciente::updateOrCreate(
                    [
                        'cedula' => $item['cedula']
                    ],
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

                // 2. Asociar paciente con el médico guardando el numhistoria y reg_medico en la tabla pivote
                $numHistoriaPowerBuilder = $item['numhistoria'] ?? null;

                $medico->pacientes()->syncWithoutDetaching([
                    $paciente->id => [
                        'numhistoria' => $numHistoriaPowerBuilder,
                        'reg_medico'  => $regMedico,
                    ]
                ]);

                // 3. Crear o actualizar la Historia del paciente
                Historia::updateOrCreate(
                    [
                        'paciente_id' => $paciente->id,
                        'medico_id'   => $medico->id,
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
                'success' => true,
                'message' => 'Sincronización finalizada exitosamente.',
                'procesados' => $registrosProcesados,
                'errores' => $errores
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('Error sincronizando pacientes: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Ocurrió un error al sincronizar los pacientes.',
                'error'   => $e->getMessage()
            ], 500);
        }
    }
}