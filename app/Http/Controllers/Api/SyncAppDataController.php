<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cola;
use App\Models\Consulta;
use App\Models\Historia;
use App\Models\Medico;
use App\Models\MedicalCenter;
use App\Models\MedicoPaciente;
use App\Models\MedicoRegistro;
use App\Models\MotivoCita;
use App\Models\Paciente;
use App\Models\SyncChange;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * Sync delta de AppDDR (ver ROADMAP.md "Decisión de arquitectura: sync offline delta"). No
 * reemplaza `/app/refresh-data`. Un solo endpoint para *pull* y *push*:
 *
 * 1. Aplica `changes` (si vienen) contra las tablas editables, con last-write-wins por columna
 *    y la regla de que un eliminado le gana a cualquier update posterior.
 * 2. Calcula el delta usando el `since` que llegó en la petición (el viejo, no el de ahora) —
 *    así la respuesta incluye lo que el cliente acaba de mandar, más lo de terceros.
 *
 * El cliente no necesita saber qué pasó con cada cambio que mandó: siempre sobreescribe su
 * copia local con lo que vuelve acá, gane o pierda su propio cambio el conflicto.
 */
class SyncAppDataController extends Controller
{
    /** Tablas y columnas que se pueden escribir por `changes` — todo lo demás se ignora. */
    private const WRITABLE_COLUMNS = [
        'cola' => [
            'numorden', 'atendido', 'estado', 'turno', 'motivo', 'monto',
            'hora_ini', 'hora_fin', 'tiempo', 'tipo', 'sms_text',
        ],
        'pacientes' => [
            'nac', 'cedula', 'apellidos', 'nombres', 'sexo', 'fnacimiento', 'lnacimiento',
            'codeestado', 'direccion', 'telefono', 'fingreso', 'escolaridad', 'ocupacion',
            'profesion', 'email', 'dependencia', 'sms',
        ],
    ];

    private const DELETABLE_TABLES = ['cola', 'pacientes'];

    public function sync(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['message' => 'Usuario no autenticado.'], 401);
        }

        $medicoModel = Medico::where('user_id', $user->id)->orWhere('email', $user->email)->first();
        if (!$medicoModel) {
            return response()->json(['message' => 'Esta cuenta no tiene un médico asociado.'], 403);
        }

        $since = $request->filled('since') ? Carbon::parse($request->input('since')) : null;

        $registrosMedicos = MedicoRegistro::where('medico_id', $medicoModel->id)
            ->pluck('reg_medico')
            ->filter()
            ->toArray();
        if (!empty($medicoModel->reg_medico)) {
            $registrosMedicos[] = $medicoModel->reg_medico;
        }
        $registrosMedicos = array_values(array_unique($registrosMedicos));

        $relaciones = MedicoPaciente::where('medico_id', $medicoModel->id)->get();
        $pacienteIds = $relaciones->pluck('paciente_id')->filter()->unique()->toArray();
        $numHistorias = $relaciones->pluck('numhistoria')->filter()->unique()->toArray();

        $this->applyChanges(
            $request->input('changes', []),
            $registrosMedicos,
            $relaciones,
        );

        $syncedAt = Carbon::now();

        $pacientes = $this->deltaQuery(Paciente::whereIn('id', $pacienteIds), $since)->get();

        $historias = $this->deltaQuery(
            Historia::where(function ($query) use ($medicoModel, $registrosMedicos, $numHistorias) {
                $query->where('medico_id', $medicoModel->id);
                if (!empty($registrosMedicos)) {
                    $query->orWhereIn('reg_medico', $registrosMedicos);
                }
                if (!empty($numHistorias)) {
                    $query->orWhereIn('numhistoria', $numHistorias);
                }
            }),
            $since,
        )->get();

        $colas = $this->deltaQuery(Cola::whereIn('reg_medico', $registrosMedicos), $since)->get();
        $consultas = $this->deltaQuery(Consulta::whereIn('numhistoria', $numHistorias), $since)->get();
        $motivos = $this->deltaQuery(MotivoCita::whereIn('reg_medico', $registrosMedicos), $since)->get();

        $medicalCenterIds = $historias->pluck('medical_center_id')->filter()->unique()->toArray();
        $centrosMedicos = $this->deltaQuery(MedicalCenter::whereIn('id', $medicalCenterIds), $since)->get();

        // Sin `since` (primera sincronización) el cliente no tiene nada que borrar todavía.
        $eliminados = $since
            ? SyncChange::where('operation', 'deleted')
                ->whereIn('reg_medico', $registrosMedicos)
                ->where('occurred_at', '>', $since)
                ->get(['table_name', 'record_id', 'occurred_at'])
            : collect([]);

        return response()->json([
            'synced_at' => $syncedAt->toIso8601String(),
            'pacientes' => $pacientes,
            'historias' => $historias,
            'colas' => $colas,
            'consultas' => $consultas,
            'motivos' => $motivos,
            'centros_medicos' => $centrosMedicos,
            'eliminados' => $eliminados,
        ]);
    }

    /**
     * Aplica las operaciones que mandó el cliente. No devuelve nada — el resultado se ve
     * reflejado en el delta que se calcula después, usando el `since` viejo.
     */
    private function applyChanges(array $changes, array $registrosMedicos, $relaciones): void
    {
        $pacienteIdsDelMedico = $relaciones->pluck('paciente_id')->filter()->unique()->toArray();

        foreach ($changes as $change) {
            $table = $change['table'] ?? null;
            $recordId = $change['record_id'] ?? null;
            $operation = $change['operation'] ?? null;

            if (!in_array($table, self::DELETABLE_TABLES, true) || !$recordId || !$operation) {
                continue;
            }

            // Autorización: el registro tiene que pertenecer al tenant de este médico.
            if ($table === 'cola') {
                $pertenece = Cola::where('id', $recordId)->whereIn('reg_medico', $registrosMedicos)->exists();
            } else {
                $pertenece = in_array($recordId, $pacienteIdsDelMedico, true);
            }
            if (!$pertenece) {
                continue;
            }

            $yaEliminado = SyncChange::where('table_name', $table)
                ->where('record_id', $recordId)
                ->where('operation', 'deleted')
                ->exists();

            if ($operation === 'deleted') {
                if (!$yaEliminado) {
                    $this->modelFor($table)::find($recordId)?->delete();
                }
                continue;
            }

            if ($operation === 'updated') {
                // Regla: un eliminado le gana a cualquier update posterior, sin importar timestamp.
                if ($yaEliminado) {
                    continue;
                }

                $column = $change['column'] ?? null;
                if (!in_array($column, self::WRITABLE_COLUMNS[$table] ?? [], true)) {
                    continue;
                }

                $occurredAt = Carbon::parse($change['occurred_at'] ?? now());

                // Last-write-wins por columna: si ya hay un cambio más nuevo registrado para
                // esta columna puntual, se descarta el que llegó (alguien escribió después).
                $ultimoCambio = SyncChange::where('table_name', $table)
                    ->where('record_id', $recordId)
                    ->where('column_name', $column)
                    ->orderByDesc('occurred_at')
                    ->first();

                if ($ultimoCambio && $ultimoCambio->occurred_at->greaterThanOrEqualTo($occurredAt)) {
                    continue;
                }

                $model = $this->modelFor($table)::find($recordId);
                if (!$model) {
                    continue;
                }

                $valor = $change['value'] ?? null;
                $model->{$column} = $valor;
                $model->save();

                SyncChange::create([
                    'reg_medico' => $table === 'cola' ? $model->reg_medico : $this->regMedicoDePaciente($relaciones, $recordId),
                    'table_name' => $table,
                    'record_id' => $recordId,
                    'operation' => 'updated',
                    'column_name' => $column,
                    'value' => $valor,
                    'occurred_at' => $occurredAt,
                    'source' => 'mobile',
                ]);
            }
        }
    }

    private function regMedicoDePaciente($relaciones, int $pacienteId): ?string
    {
        return $relaciones->firstWhere('paciente_id', $pacienteId)?->reg_medico;
    }

    private function modelFor(string $table): string
    {
        return $table === 'cola' ? Cola::class : Paciente::class;
    }

    private function deltaQuery($query, ?Carbon $since)
    {
        if ($since) {
            $query->where('updated_at', '>', $since);
        }

        return $query;
    }
}
