<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SyncAppDataRequest;
use App\Http\Resources\ConfiguracionMedicoResource;
use App\Models\Cola;
use App\Models\Consulta;
use App\Models\Evolucion;
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
use Illuminate\Support\Facades\DB;

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
    public function sync(SyncAppDataRequest $request)
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
            $request->cambios(),
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
            // La configuración va completa en cada respuesta, no por delta: son un puñado de
            // campos y el cliente la necesita entera para decidir cómo dibujar la agenda.
            // Si el médico no tiene fila de configuración (pasa: en el dump real está vacía),
            // el Resource resuelve los defaults sobre un modelo en blanco.
            'configuracion' => new ConfiguracionMedicoResource(
                Evolucion::whereIn('reg_medico', $registrosMedicos)->first() ?? new Evolucion(),
            ),
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

            if ($operation === 'reorder') {
                $this->applyReorder($change, $registrosMedicos);
                continue;
            }

            if (!in_array($table, SyncAppDataRequest::DELETABLE_TABLES, true) || !$recordId || !$operation) {
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
                if (!in_array($column, SyncAppDataRequest::WRITABLE_COLUMNS[$table] ?? [], true)) {
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
                if (!$this->valorPermitido($table, $column, $valor)) {
                    continue;
                }

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
        if (!isset(SyncAppDataRequest::CREATABLE_COLUMNS[$table]) || $tempId === null || !is_numeric($tempId)) {
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
            array_flip(SyncAppDataRequest::CREATABLE_COLUMNS[$table]),
        );

        foreach (SyncAppDataRequest::REQUIRED_COLUMNS[$table] ?? [] as $obligatoria) {
            if (($columnas[$obligatoria] ?? null) === null) {
                $rechazar("Falta el campo obligatorio '{$obligatoria}'.");
                return;
            }
        }

        foreach ($columnas as $columna => $valor) {
            if (!$this->valorPermitido($table, $columna, $valor)) {
                $rechazar("El valor de '{$columna}' no es válido.");
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

    /** Ver `ALLOWED_VALUES`. Una columna sin dominio declarado acepta cualquier valor. */
    private function valorPermitido(string $table, string $column, $valor): bool
    {
        $permitidos = SyncAppDataRequest::ALLOWED_VALUES[$table][$column] ?? null;

        if ($permitidos === null || $valor === null) {
            return true;
        }

        return is_numeric($valor) && in_array((int) $valor, $permitidos, true);
    }

    /**
     * Mueve una fila dentro de su grupo (una cita dentro de su día) corriendo las demás.
     *
     * **Por qué una operación propia y no N `updated`**: el cliente podría mandar el número de
     * orden nuevo de cada fila desplazada, pero mover una cita en un día de 20 son 20 cambios
     * para un gesto, 20 UPDATE, y —lo importante— 20 resoluciones de conflicto independientes:
     * dos secretarias reordenando a la vez se pisan fila por fila y el resultado no es el de
     * ninguna de las dos. Mandando **el movimiento** en vez del resultado, el desplazamiento son
     * dos sentencias (`UPDATE … WHERE … BETWEEN`) y dos reordenamientos concurrentes se componen
     * en vez de destruirse.
     *
     * - Hacia arriba (`to < from`): la fila movida pasa a `to` y las que estaban entre medio
     *   **suben uno**.
     * - Hacia abajo (`to > from`): la fila movida pasa a `to` y las de en medio **bajan uno**.
     *
     * Funciona con numeraciones con huecos (los datos legados las tienen): la fila movida libera
     * su lugar, así que el corrimiento nunca colisiona.
     */
    private function applyReorder(array $change, array $registrosMedicos): void
    {
        $table = $change['table'] ?? null;
        $columna = SyncAppDataRequest::ORDER_COLUMN[$table] ?? null;
        $columnaGrupo = SyncAppDataRequest::ORDER_SCOPE_COLUMN[$table] ?? null;
        if ($columna === null || $columnaGrupo === null) {
            return;
        }

        $recordId = (int) $change['record_id'];
        $desde = (int) $change['from'];
        $hasta = (int) $change['to'];
        if ($desde === $hasta) {
            return;
        }

        $modelo = $this->modelFor($table);

        // Tenancy: la fila tiene que ser de este médico, igual que en el resto del endpoint.
        $fila = $modelo::where('id', $recordId)->whereIn('reg_medico', $registrosMedicos)->first();
        if (!$fila) {
            return;
        }

        $grupo = fn () => $modelo::whereIn('reg_medico', $registrosMedicos)
            ->whereDate($columnaGrupo, $change['scope_date'])
            ->where('id', '!=', $recordId);

        // En una transacción: entre el corrimiento y el movimiento de la fila, el orden está a
        // medio aplicar y nadie debería leerlo así.
        DB::transaction(function () use ($grupo, $fila, $columna, $desde, $hasta) {
            if ($hasta < $desde) {
                $grupo()->whereBetween($columna, [$hasta, $desde])->increment($columna);
            } else {
                $grupo()->whereBetween($columna, [$desde, $hasta])->decrement($columna);
            }

            $fila->{$columna} = $hasta;
            $fila->save();
        });

        SyncChange::create([
            'reg_medico' => $fila->reg_medico,
            'table_name' => $table,
            'record_id' => $recordId,
            'operation' => 'reorder',
            'column_name' => $columna,
            'value' => "{$desde}->{$hasta}",
            'occurred_at' => Carbon::parse($change['occurred_at'] ?? now()),
            'source' => 'mobile',
        ]);
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
