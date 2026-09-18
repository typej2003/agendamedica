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
use App\Models\Recipe;
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

    /**
     * Columnas aceptadas al *crear* una fila. Es una lista aparte de `WRITABLE_COLUMNS` porque
     * hay campos que solo tienen sentido al nacer la cita (`fecha`, `numhistoria`): editarlos
     * después es "reagendar" o "cambiar de paciente", que son acciones con reglas propias
     * todavía sin definir (ver Docs/Wiki/02-modulo-agenda.md, hueco de "reagendar").
     *
     * `reg_medico` y `medico` no se aceptan del cliente a propósito — los resuelve el servidor
     * desde el médico autenticado, que es lo único que marca el tenant.
     */
    private const CREATABLE_COLUMNS = [
        'cola' => [
            'fecha', 'hora_ini', 'hora_fin', 'numhistoria', 'numorden', 'atendido',
            'estado', 'turno', 'motivo', 'monto', 'tiempo', 'tipo', 'sms_text',
        ],
    ];

    /** Columnas `NOT NULL` en el esquema — sin ellas el INSERT explota, mejor rechazar antes. */
    private const REQUIRED_COLUMNS = [
        'cola' => ['fecha', 'hora_ini'],
    ];

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

        $resultadoChanges = $this->applyChanges(
            $request->input('changes', []),
            $medicoModel,
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
        // Fase 1: récipes es solo lectura desde el app, no hay `changes` que aplicarle.
        $recipes = $this->deltaQuery(Recipe::whereIn('nrohistoria', $numHistorias), $since)->get();

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
            'recipes' => $recipes,
            'centros_medicos' => $centrosMedicos,
            'eliminados' => $eliminados,
            // Mapeo id temporal del cliente → id real, para que pueda soltar su fila provisional.
            'creados' => $resultadoChanges['creados'],
            // Creaciones que no se aplicaron (y no se van a aplicar reintentando): el cliente las
            // saca de su cola en vez de reintentarlas para siempre, y le avisa al usuario.
            'rechazados' => $resultadoChanges['rechazados'],
        ]);
    }

    /**
     * Aplica las operaciones que mandó el cliente. Lo único que devuelve es lo que el cliente no
     * puede deducir solo: qué id real le tocó a cada fila que creó offline, y qué creaciones se
     * rechazaron. El resto del resultado se ve reflejado en el delta que se calcula después,
     * usando el `since` viejo.
     *
     * @return array{creados: list<array{table: string, temp_id: int, id: int}>, rechazados: list<array{table: string, temp_id: int, motivo: string}>}
     */
    private function applyChanges(array $changes, Medico $medico, array $registrosMedicos, $relaciones): array
    {
        $pacienteIdsDelMedico = $relaciones->pluck('paciente_id')->filter()->unique()->toArray();
        $creados = [];
        $rechazados = [];

        foreach ($changes as $change) {
            $table = $change['table'] ?? null;
            $recordId = $change['record_id'] ?? null;
            $operation = $change['operation'] ?? null;

            if ($operation === 'created') {
                $this->applyCreated($change, $medico, $registrosMedicos, $creados, $rechazados);
                continue;
            }

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

        return ['creados' => $creados, 'rechazados' => $rechazados];
    }

    /**
     * Crea una fila que el cliente ya venía mostrando localmente con un id temporal (negativo).
     *
     * Tres cosas que no son obvias:
     * 1. **Idempotencia por `client_temp_id`**: si la respuesta anterior se perdió, el reintento
     *    devuelve el mismo id real en vez de crear una cita duplicada.
     * 2. **El tenant no se acepta del cliente**: `reg_medico` sale de la historia del paciente
     *    (verificada contra este médico), no de lo que haya mandado el teléfono.
     * 3. **Rechazo explícito**: descartar en silencio dejaría al cliente reintentando para
     *    siempre una cita que nunca va a entrar, y al médico viéndola como "pendiente" sin fin.
     */
    private function applyCreated(array $change, Medico $medico, array $registrosMedicos, array &$creados, array &$rechazados): void
    {
        $table = $change['table'] ?? null;
        $tempId = $change['temp_id'] ?? null;

        // Sin `temp_id` no hay forma de contestarle al cliente cuál fila es cuál, así que no se
        // crea nada: aplicarla sería dejarle una fila fantasma imposible de reconciliar.
        if (!isset(self::CREATABLE_COLUMNS[$table]) || $tempId === null || !is_numeric($tempId)) {
            return;
        }
        $tempId = (int) $tempId;

        $yaCreado = SyncChange::where('table_name', $table)
            ->where('client_temp_id', $tempId)
            ->where('operation', 'created')
            ->whereIn('reg_medico', $registrosMedicos)
            ->first();

        if ($yaCreado) {
            $creados[] = ['table' => $table, 'temp_id' => $tempId, 'id' => $yaCreado->record_id];
            return;
        }

        $rechazar = function (string $motivo) use ($table, $tempId, &$rechazados) {
            $rechazados[] = ['table' => $table, 'temp_id' => $tempId, 'motivo' => $motivo];
        };

        $columnas = array_intersect_key(
            (array) ($change['columns'] ?? []),
            array_flip(self::CREATABLE_COLUMNS[$table]),
        );

        foreach (self::REQUIRED_COLUMNS[$table] ?? [] as $obligatoria) {
            if (($columnas[$obligatoria] ?? null) === null) {
                $rechazar("Falta el campo obligatorio '{$obligatoria}'.");
                return;
            }
        }

        $numhistoria = $columnas['numhistoria'] ?? null;
        if ($numhistoria === null) {
            $rechazar('La cita no indica número de historia.');
            return;
        }

        $historia = Historia::where('numhistoria', $numhistoria)
            ->whereIn('reg_medico', $registrosMedicos)
            ->first();

        if (!$historia) {
            $rechazar('El número de historia no pertenece a este médico.');
            return;
        }

        $columnas['reg_medico'] = $historia->reg_medico;
        $columnas['medico'] = $medico->id;

        $cola = Cola::create($columnas);

        SyncChange::create([
            'reg_medico' => $historia->reg_medico,
            'table_name' => $table,
            'record_id' => $cola->id,
            'client_temp_id' => $tempId,
            'operation' => 'created',
            'occurred_at' => Carbon::parse($change['occurred_at'] ?? now()),
            'source' => 'mobile',
        ]);

        $creados[] = ['table' => $table, 'temp_id' => $tempId, 'id' => $cola->id];
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
