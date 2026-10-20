<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Paciente;
use App\Models\Medico;
use App\Models\Historia;
use App\Models\MedicoPaciente;
use App\Services\SyncAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PacienteSyncController extends Controller
{
    /**
     * Sincroniza y guarda los registros de pacientes enviados desde PowerBuilder.
     *
     * **El vínculo es del consultorio (tenant)**, no del médico: `medico_pacientes` tiene una sola fila
     * por `(reg_medico, paciente_id)` desde la migración del 2026-10-20. Por eso:
     *
     *  - el `reg_medico` que manda la credencial (o el payload) es el del **consultorio**, y si el
     *    payload no lo trae se **deriva del `medico_id`** del propio payload (`medicos.reg_medico`), que
     *    es como lo manda el escritorio viejo;
     *  - el vínculo se escribe con **upsert por `(reg_medico, paciente_id)`**: si el paciente ya estaba
     *    vinculado a ese consultorio no se duplica la fila y se **conserva su `medico_id`** (el doctor
     *    que lo vinculó, o el centro);
     *  - `historias` es la historia **del consultorio**: se busca por `(reg_medico, paciente_id)` y no por
     *    el médico, así que el mismo paciente en dos consultorios tiene una historia en cada uno.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function sincronizar(Request $request)
    {
        // Autenticación: X-API-KEY (transición) o credencial por equipo (Bearer). Este endpoint
        // estaba SIN autenticación hasta 2026-09-27: cualquiera podía insertar pacientes de
        // cualquier médico mandando su reg_medico en el payload.
        $auth = app(SyncAuthService::class)->validar($request);
        if ($auth['status'] !== SyncAuthService::OK) {
            return app(SyncAuthService::class)->respuestaError($auth);
        }

        $data = $request->all();

        // Extraer pacientes ya sea que vengan en un array o como objeto único
        $pacientes = isset($data['pacientes']) ? $data['pacientes'] : (isset($data[0]) ? $data : [$data]);

        // Tomar referencias del request o del primer objeto del payload
        $primerRegistro = is_array($data) && isset($data[0]) ? $data[0] : $data;
        $regMedico = $primerRegistro['reg_medico'] ?? $request->input('reg_medico');

        // Con credencial de equipo el médico lo manda la CREDENCIAL, no el payload.
        if (! empty($auth['reg_medico'])) {
            $regMedico = $auth['reg_medico'];
        }

        // Búsqueda del médico por reg_medico
        $medico = Medico::where('reg_medico', $regMedico)->first();

        // El payload viejo puede no traer `reg_medico` y sí el `medico_id`: el consultorio se deriva de
        // su ficha (`medicos.reg_medico`), que es el tenant con el que se guardan sus cosas. El id puede
        // venir en la raíz del envío o en el primer paciente del lote.
        if (! $medico) {
            $primeroDelLote = is_array($pacientes) ? ($pacientes[array_key_first($pacientes)] ?? null) : null;

            $medico = $this->medicoDelPayload($primerRegistro)
                ?? $this->medicoDelPayload(is_array($primeroDelLote) ? $primeroDelLote : [])
                ?? $this->medicoDelPayload(['medico_id' => $request->input('medico_id')]);
            $regMedico = $medico?->reg_medico;
        }

        // Validar existencia del médico
        if (!$medico || ! $regMedico) {
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

                // 2. Asociar paciente con el consultorio guardando el numhistoria y reg_medico en el vínculo.
                //    Es **upsert por (reg_medico, paciente_id)**: una sola fila por paciente y consultorio.
                //    Si ya existía se conserva su `medico_id` (el doctor que lo vinculó o el centro) y sólo
                //    se refresca la copia del número que lee el app viejo.
                $numHistoriaPowerBuilder = $item['numhistoria'] ?? null;

                $vinculo = MedicoPaciente::firstOrNew([
                    'reg_medico'  => $regMedico,
                    'paciente_id' => $paciente->id,
                ]);

                if (! $vinculo->exists) {
                    $vinculo->medico_id = $medico->id;
                }

                $vinculo->numhistoria = $numHistoriaPowerBuilder;
                $vinculo->save();

                // 3. Crear o actualizar la Historia **del consultorio** (no una por médico): la clave es
                //    `(reg_medico, paciente_id)` y el `medico_id` es sólo el doctor que la registra.
                Historia::updateOrCreate(
                    [
                        'reg_medico'  => $regMedico,
                        'paciente_id' => $paciente->id,
                    ],
                    [
                        'numhistoria'       => $numHistoriaPowerBuilder,
                        'medico_id'         => $medico->id,
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

    /**
     * El médico del payload viejo, cuando el envío **no trae `reg_medico`** pero sí el `medico_id` (el
     * `medicos.id` de la cuenta que sube). De su ficha sale el consultorio (`medicos.reg_medico`), que es
     * el tenant con el que se guardan el vínculo y la historia.
     *
     * Acepta las dos formas en que el escritorio puede mandarlo: `{medico_id}` o `{medico: {id}}`.
     *
     * @param  array<string,mixed>  $registro
     */
    private function medicoDelPayload(array $registro): ?Medico
    {
        $id = $registro['medico_id'] ?? ($registro['medico']['id'] ?? null);

        if ($id === null || $id === '') {
            return null;
        }

        return Medico::find($id);
    }
}