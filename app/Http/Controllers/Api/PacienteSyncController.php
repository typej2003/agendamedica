<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Paciente;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PacienteSyncController extends Controller
{
    /**
     * Sincroniza y guarda los registros de pacientes enviados desde el cliente.
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

        // Búsqueda del médico por reg-medico
        $medico = Medico::where('reg-medico', $regMedico)->first();

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
                if (!isset($item['numhistoria'])) {
                    $errores[] = [
                        'posicion' => $index,
                        'error' => 'El registro no contiene el campo primario numhistoria.'
                    ];
                    continue;
                }

                // 1. Crear o actualizar el paciente por numhistoria
                $paciente = Paciente::updateOrCreate(
                    [
                        'numhistoria' => $item['numhistoria']
                    ],
                    [
                        'nac'         => $item['nac'] ?? null,
                        'cedula'      => $item['cedula'] ?? null,
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

                // 2. Vincular el médico con el paciente en la tabla pivote evitando duplicados
                MedicoPaciente::firstOrCreate([
                    'medico_id'   => $medico->id,
                    'paciente_id' => $paciente->numhistoria,
                ]);

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