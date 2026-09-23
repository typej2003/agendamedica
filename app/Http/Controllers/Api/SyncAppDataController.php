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
use App\Models\Office;
use App\Models\OfficeSchedule;
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

        // Se relee el pivote **después** de aplicar los cambios: un paciente creado en este mismo
        // lote no estaba en `$relaciones` cuando se cargó arriba, y sin esto el delta no se lo
        // devolvería al teléfono — la ficha que el usuario acaba de crear desaparecería de su
        // pantalla en la primera sincronización.
        if ($resultadoChanges['creo_pacientes']) {
            $relaciones = MedicoPaciente::where('medico_id', $medicoModel->id)->get();
            $pacienteIds = $relaciones->pluck('paciente_id')->filter()->unique()->toArray();
            $numHistorias = $relaciones->pluck('numhistoria')->filter()->unique()->toArray();
        }

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

        // Dónde atiende el médico: los consultorios con sus bloques de trabajo. Es lo que le
        // permite al app saber en qué jornada cae una cita, con qué modalidad se trabaja en esa
        // sede y cuándo se llegó al cupo — todo se calcula con la hora, sin guardar la office en
        // la cita (ver la migración de `cola.medical_center_id`).
        $offices = $this->deltaQuery(
            Office::where('medico_id', $medicoModel->id)
                ->orWhere(function ($query) use ($registrosMedicos) {
                    $query->whereNull('medico_id')->whereIn('reg_medico', $registrosMedicos);
                }),
            $since,
        )->get();

        $officeSchedules = $this->deltaQuery(
            OfficeSchedule::whereIn('office_id', Office::where('medico_id', $medicoModel->id)->select('id')),
            $since,
        )->get();

        // Los centros que necesita el teléfono son los de las historias **y** los de las sedes
        // donde atiende: sin esto, una sede recién configurada llegaría sin nombre ni dirección.
        $medicalCenterIds = $historias->pluck('medical_center_id')
            ->merge($offices->pluck('medical_center_id'))
            ->merge($colas->pluck('medical_center_id'))
            ->filter()
            ->unique()
            ->toArray();
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
            'offices' => $offices,
            'office_schedules' => $officeSchedules,
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
        // Id temporal del cliente → id real, para las citas del mismo lote que referencian a un
        // paciente recién creado (ver `paciente_temp_id`).
        $pacientesCreados = [];

        // Las creaciones de pacientes van primero: una cita del mismo lote puede depender de una
        // de ellas, y el cliente no tiene forma de garantizar el orden del arreglo.
        $changes = $this->pacientesPrimero($changes);

        foreach ($changes as $change) {
            $table = $change['table'] ?? null;
            $recordId = $change['record_id'] ?? null;
            $operation = $change['operation'] ?? null;

            if ($operation === 'created') {
                $this->applyCreated(
                    $change,
                    $medico,
                    $registrosMedicos,
                    $creados,
                    $rechazados,
                    $pacientesCreados,
                );
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

        return [
            'creados' => $creados,
            'rechazados' => $rechazados,
            'creo_pacientes' => $pacientesCreados !== [],
        ];
    }

    /** @param list<array> $changes @return list<array> */
    private function pacientesPrimero(array $changes): array
    {
        $pacientes = [];
        $resto = [];
        foreach ($changes as $change) {
            $esPacienteNuevo = ($change['operation'] ?? null) === 'created'
                && ($change['table'] ?? null) === 'pacientes';
            if ($esPacienteNuevo) {
                $pacientes[] = $change;
            } else {
                $resto[] = $change;
            }
        }

        return [...$pacientes, ...$resto];
    }

    /**
     * Crea una fila que el cliente ya venía mostrando localmente con su propio id.
     *
     * Tres cosas que no son obvias:
     * 1. **Idempotencia por `client_temp_id`**: si la respuesta anterior se perdió, el reintento
     *    devuelve el mismo id real en vez de crear una fila duplicada.
     * 2. **El tenant no se acepta del cliente**: `reg_medico` sale del médico autenticado (o de
     *    la historia del paciente), no de lo que haya mandado el teléfono.
     * 3. **Rechazo explícito**: descartar en silencio dejaría al cliente reintentando para
     *    siempre una fila que nunca va a entrar, y al médico viéndola como "pendiente" sin fin.
     *
     * @param array<int, int> $pacientesCreados id temporal -> id real de los pacientes del lote
     */
    private function applyCreated(
        array $change,
        Medico $medico,
        array $registrosMedicos,
        array &$creados,
        array &$rechazados,
        array &$pacientesCreados
    ): void {
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
            // El reintento de una cita puede venir con el paciente ya creado en un lote anterior:
            // la referencia tiene que seguir resolviendo.
            if ($table === 'pacientes') {
                $pacientesCreados[$tempId] = $yaCreado->record_id;
            }
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

        $occurredAt = Carbon::parse($change['occurred_at'] ?? now());

        if ($table === 'pacientes') {
            $this->crearPaciente(
                $columnas,
                $medico,
                $registrosMedicos,
                $tempId,
                $occurredAt,
                $creados,
                $pacientesCreados,
                $rechazar,
            );

            return;
        }

        $this->crearCola(
            $columnas,
            $change,
            $medico,
            $registrosMedicos,
            $tempId,
            $occurredAt,
            $creados,
            $pacientesCreados,
            $rechazar,
        );
    }

    /**
     * Alta de paciente desde el app. Replica lo que hace `PacienteSyncController` con lo que sube
     * el escritorio, con una diferencia que importa: **acá no hay `numhistoria`**.
     *
     * La ficha se busca por cédula antes de crearla porque en este esquema un paciente es uno
     * solo para todos los médicos (`pacientes`) y lo que cambia por médico es la relación
     * (`medico_pacientes`): si la secretaria da de alta a alguien que ya existe, corresponde
     * enlazarlo, no duplicarle la ficha.
     *
     * **No se crea `Historia`** justamente porque su `numhistoria` es único global y obligatorio:
     * ese número lo asigna el escritorio, y cuando lo haga, su propio sync crea la historia
     * (`Historia::updateOrCreate` por paciente + médico). Hasta entonces el paciente le llega al
     * teléfono igual, porque el delta de pacientes se calcula por el pivote, no por la historia.
     */
    private function crearPaciente(
        array $columnas,
        Medico $medico,
        array $registrosMedicos,
        int $tempId,
        Carbon $occurredAt,
        array &$creados,
        array &$pacientesCreados,
        callable $rechazar
    ): void {
        $regMedico = $medico->reg_medico ?: ($registrosMedicos[0] ?? null);
        if ($regMedico === null) {
            $rechazar('Este médico no tiene registro asignado.');
            return;
        }

        $paciente = DB::transaction(function () use ($columnas, $medico, $regMedico) {
            $paciente = Paciente::where('cedula', $columnas['cedula'])->first();

            if ($paciente) {
                // Ya existe (lo atiende otro médico, o el escritorio lo subió antes): se enlaza y
                // solo se completan los campos vacíos. Pisar los datos de una ficha ajena con lo
                // que escribió la secretaria en el teléfono sería perder información de otro.
                $completar = [];
                foreach ($columnas as $columna => $valor) {
                    if ($valor !== null && $paciente->{$columna} === null) {
                        $completar[$columna] = $valor;
                    }
                }
                if ($completar !== []) {
                    $paciente->fill($completar)->save();
                }
            } else {
                $paciente = Paciente::create($columnas);
            }

            MedicoPaciente::firstOrCreate(
                ['medico_id' => $medico->id, 'paciente_id' => $paciente->id],
                ['reg_medico' => $regMedico, 'numhistoria' => null],
            );

            return $paciente;
        });

        SyncChange::create([
            'reg_medico' => $regMedico,
            'table_name' => 'pacientes',
            'record_id' => $paciente->id,
            'client_temp_id' => $tempId,
            'operation' => 'created',
            'occurred_at' => $occurredAt,
            'source' => 'mobile',
        ]);

        $pacientesCreados[$tempId] = $paciente->id;
        $creados[] = ['table' => 'pacientes', 'temp_id' => $tempId, 'id' => $paciente->id];
    }

    /**
     * Alta de cita. El paciente llega de una de dos formas, nunca de las dos:
     *
     * - **`numhistoria`**: el camino normal. Se verifica contra `historias` que sea de este médico.
     * - **`paciente_temp_id`**: el paciente se creó en este mismo lote (o en uno anterior que el
     *   cliente todavía no vio resuelto) y no tiene número de historia. La cita se ancla con
     *   `paciente_sinhistoria_id`, la columna que el esquema legado ya trae para este caso.
     */
    private function crearCola(
        array $columnas,
        array $change,
        Medico $medico,
        array $registrosMedicos,
        int $tempId,
        Carbon $occurredAt,
        array &$creados,
        array $pacientesCreados,
        callable $rechazar
    ): void {
        $numhistoria = $columnas['numhistoria'] ?? null;
        $pacienteTempId = $change['paciente_temp_id'] ?? null;

        if ($numhistoria !== null) {
            $historia = Historia::where('numhistoria', $numhistoria)
                ->whereIn('reg_medico', $registrosMedicos)
                ->first();

            if (!$historia) {
                $rechazar('El número de historia no pertenece a este médico.');
                return;
            }

            $columnas['reg_medico'] = $historia->reg_medico;
        } elseif ($pacienteTempId !== null) {
            $pacienteId = $pacientesCreados[(int) $pacienteTempId] ?? null;
            if ($pacienteId === null) {
                // O la creación del paciente venía en este lote y fue rechazada, o la referencia
                // no corresponde a nada. Reintentar no lo va a arreglar: dejarla en la cola
                // sería tener una cita pendiente para siempre.
                $rechazar('El paciente de esta cita no se pudo crear.');
                return;
            }

            $relacion = MedicoPaciente::where('medico_id', $medico->id)
                ->where('paciente_id', $pacienteId)
                ->first();
            if (!$relacion) {
                $rechazar('Ese paciente no es de este médico.');
                return;
            }

            $columnas['paciente_sinhistoria_id'] = $pacienteId;
            $columnas['reg_medico'] = $relacion->reg_medico
                ?: ($medico->reg_medico ?: ($registrosMedicos[0] ?? null));
        } else {
            $rechazar('La cita no indica a qué paciente pertenece.');
            return;
        }

        $columnas['medico'] = $medico->id;

        $cola = Cola::create($columnas);

        SyncChange::create([
            'reg_medico' => $cola->reg_medico,
            'table_name' => 'cola',
            'record_id' => $cola->id,
            'client_temp_id' => $tempId,
            'operation' => 'created',
            'occurred_at' => $occurredAt,
            'source' => 'mobile',
        ]);

        $creados[] = ['table' => 'cola', 'temp_id' => $tempId, 'id' => $cola->id];
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
