<?php

namespace App\Sync;

use App\Models\Cola;
use App\Models\Consulta;
use App\Models\Historia;
use App\Models\Medico;
use App\Models\MedicoPaciente;
use App\Models\Recipe;
use App\Models\RecipeDetalle;
use App\Models\SyncChange;
use Carbon\Carbon;
use DateTime;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Creaciones del flujo de atención (ROADMAP.md Paso 18.B): **llenar la historia** de un paciente dado
 * de alta sin número, **abrir una consulta** al atender y **guardar un récipe** dentro de ella.
 *
 * Tres reglas que atraviesan las tres:
 *
 * 1. **El número lo asigna el servidor, con el correlativo vigente** (historia: la última del médico
 *    + 1; consulta: la última de esa historia + 1; récipe: el siguiente de la consulta). El teléfono
 *    muestra uno provisional y lo reemplaza por el que vuelve en `creados` (decisión del usuario,
 *    2026-09-23). Por eso no viajan columnas: solo a qué cuelga cada cosa.
 * 2. **Cada una cuelga de la anterior**, que puede venir en el mismo lote (id temporal:
 *    `historia_temp_id`, `consulta_temp_id`) o ya existir (número o id real). Una referencia temporal
 *    de un lote anterior se resuelve por `sync_changes`, así que el reintento de un récipe cuya
 *    consulta ya se creó sigue funcionando.
 * 3. **Idempotencia por `client_temp_id`**, igual que citas y pacientes: si la respuesta anterior se
 *    perdió, el reintento devuelve lo mismo en vez de numerar otra vez.
 *
 * Las filas nuevas son de las tablas legadas reales (`consultas`, `recipes`, `recipe_detalle`), así
 * que el día que el sync del PowerBuilder las baje las va a encontrar como si las hubiera creado él
 * (ver PENDIENTES-POWERBUILDER.md).
 */
class CreacionesClinicas
{
    /** @var array<int, int> id temporal → id real de las historias creadas en este lote */
    private array $historias = [];

    /** @var array<int, int> id temporal → id real de las consultas creadas en este lote */
    private array $consultas = [];

    public function __construct(
        private Medico $medico,
        private array $registrosMedicos,
    ) {
    }

    /**
     * @param array<int, int> $pacientesCreados id temporal → id real de los pacientes del lote
     */
    public function aplicar(array $change, array $pacientesCreados, array &$creados, array &$rechazados): void
    {
        $table = $change['table'];
        $tempId = (int) $change['temp_id'];

        $rechazar = function (string $motivo) use ($table, $tempId, &$rechazados) {
            $rechazados[] = ['table' => $table, 'temp_id' => $tempId, 'motivo' => $motivo];
        };

        $yaCreado = $this->yaCreado($table, $tempId);
        if ($yaCreado !== null) {
            $respuesta = $this->respuestaDe($table, $tempId, $yaCreado);
            if ($respuesta !== null) {
                $creados[] = $respuesta;
            }
            return;
        }

        $respuesta = match ($table) {
            'historias' => $this->crearHistoria($change, $tempId, $pacientesCreados, $rechazar),
            'consultas' => $this->crearConsulta($change, $tempId, $rechazar),
            'recipes' => $this->crearRecipe($change, $tempId, $rechazar),
        };
        if ($respuesta !== null) {
            $creados[] = $respuesta;
        }
    }

    /**
     * "Llenar historia": le asigna número a un paciente que lo tiene de este médico sin historia. Los
     * datos de la ficha no viajan acá: ya se editan con `updated` sobre `pacientes`.
     */
    private function crearHistoria(array $change, int $tempId, array $pacientesCreados, callable $rechazar): ?array
    {
        $pacienteId = $change['paciente_id'] ?? null;
        if ($pacienteId === null && isset($change['paciente_temp_id'])) {
            $pacienteTempId = (int) $change['paciente_temp_id'];
            $pacienteId = $pacientesCreados[$pacienteTempId] ?? $this->yaCreado('pacientes', $pacienteTempId);
        }
        if ($pacienteId === null) {
            $rechazar('La historia no indica de qué paciente es.');
            return null;
        }

        $relacion = MedicoPaciente::where('medico_id', $this->medico->id)
            ->where('paciente_id', (int) $pacienteId)
            ->first();
        if (!$relacion) {
            $rechazar('Ese paciente no es de este médico.');
            return null;
        }

        // Otro teléfono (o el escritorio) ya le llenó la historia: no se numera dos veces, se devuelve
        // la que tiene. El teléfono reemplaza su provisional por esa.
        if ($relacion->numhistoria !== null) {
            $historia = Historia::where('paciente_id', $relacion->paciente_id)
                ->where('medico_id', $this->medico->id)
                ->first();
            if ($historia) {
                $this->anotar('historias', $historia->id, $tempId, $historia->reg_medico);
                $this->historias[$tempId] = $historia->id;
                return $this->respuestaHistoria($tempId, $historia);
            }
        }

        $regMedico = $relacion->reg_medico ?: $this->medico->regMedicoPrincipal();

        // Dos teléfonos llenando historias a la vez pueden calcular el mismo número: la unicidad
        // `(reg_medico, numhistoria)` hace que uno falle, y ese reintenta con el siguiente.
        for ($intento = 0; ; $intento++) {
            try {
                $historia = DB::transaction(function () use ($relacion, $regMedico) {
                    $numero = $this->siguienteHistoria();

                    $historia = Historia::create([
                        'numhistoria' => (string) $numero,
                        'reg_medico' => $regMedico,
                        'paciente_id' => $relacion->paciente_id,
                        'medico_id' => $this->medico->id,
                    ]);
                    $relacion->numhistoria = (string) $numero;
                    $relacion->save();

                    // Las citas que tenía "sin historia" pasan a referenciarla como las del legado.
                    Cola::where('paciente_sinhistoria_id', $relacion->paciente_id)
                        ->whereIn('reg_medico', $this->registrosMedicos)
                        ->whereNull('numhistoria')
                        ->update(['numhistoria' => $numero]);

                    return $historia;
                });
                break;
            } catch (QueryException $e) {
                if ($intento >= 2) {
                    throw $e;
                }
            }
        }

        $this->anotar('historias', $historia->id, $tempId, $regMedico);
        $this->historias[$tempId] = $historia->id;

        return $this->respuestaHistoria($tempId, $historia);
    }

    /**
     * Abrir una consulta al atender. Siempre crea una nueva: la pregunta "¿re-atender o consulta
     * nueva?" la resuelve el teléfono (re-atender reusa la del día sin mandar nada acá).
     */
    private function crearConsulta(array $change, int $tempId, callable $rechazar): ?array
    {
        $historia = $this->resolverHistoria($change);
        if (!$historia) {
            $rechazar('La consulta no corresponde a una historia de este médico.');
            return null;
        }

        $fecha = $this->fechaValida($change['fecha'] ?? null) ?? now()->toDateString();
        $numhistoria = (int) $historia->numhistoria;

        $consulta = DB::transaction(function () use ($historia, $numhistoria, $fecha) {
            $ultima = Consulta::where('numhistoria', $numhistoria)
                ->whereIn('reg_medico', $this->registrosMedicos)
                ->max('nroconsulta');

            return Consulta::create([
                'reg_medico' => $historia->reg_medico,
                'numhistoria' => $numhistoria,
                'nroconsulta' => ((int) $ultima) + 1,
                'fecha' => $fecha,
            ]);
        });

        $this->anotar('consultas', $consulta->id, $tempId, $consulta->reg_medico);
        $this->consultas[$tempId] = $consulta->id;

        return $this->respuestaConsulta($tempId, $consulta);
    }

    /**
     * Guardar un récipe: una cabecera en `recipe_detalle` y una fila de `recipes` por medicamento,
     * todas con el mismo número de récipe dentro de la consulta. Viaja como **una** creación con sus
     * `items` y no como una por fila: un récipe a medio crear (la mitad de los medicamentos) no tiene
     * sentido ni para imprimir ni para el escritorio.
     */
    private function crearRecipe(array $change, int $tempId, callable $rechazar): ?array
    {
        $consulta = $this->resolverConsulta($change);
        if (!$consulta) {
            $rechazar('El récipe no corresponde a una consulta de este médico.');
            return null;
        }

        $items = $this->itemsValidos($change['items'] ?? null);
        if ($items === null) {
            $rechazar('El récipe no tiene medicamentos válidos.');
            return null;
        }

        $fecha = $this->fechaValida($change['fecha'] ?? null)
            ?? ($consulta->fecha ? Carbon::parse($consulta->fecha)->toDateString() : now()->toDateString());

        [$detalle, $filas] = DB::transaction(function () use ($consulta, $items, $fecha) {
            $numero = $this->siguienteRecipe($consulta);

            $detalle = RecipeDetalle::create([
                'reg_medico' => $consulta->reg_medico,
                'nrohistoria' => $consulta->numhistoria,
                'nroconsulta' => $consulta->nroconsulta,
                'recipe' => $numero,
                'fe_emision' => $fecha,
                // Como el legado: el récipe vence a los 30 días.
                'fe_vence' => 30,
            ]);

            $filas = [];
            foreach ($items as $orden => $item) {
                $filas[] = Recipe::create([
                    'reg_medico' => $consulta->reg_medico,
                    'nrohistoria' => $consulta->numhistoria,
                    'nroconsulta' => $consulta->nroconsulta,
                    'codemedicina' => $item['codemedicina'],
                    'indicaciones' => $item['indicaciones'],
                    'cantidad' => $item['cantidad'],
                    'descripcion' => $item['descripcion'],
                    'orden' => $orden + 1,
                    'fecha' => $fecha,
                    'recipe' => $numero,
                ]);
            }

            return [$detalle, $filas];
        });

        $this->anotar('recipes', $detalle->id, $tempId, $detalle->reg_medico);

        return $this->respuestaRecipe($tempId, $detalle, collect($filas));
    }

    private function siguienteHistoria(): int
    {
        // Las del médico por las dos vías en que el legado las deja: `historias` (sync de pacientes)
        // y el pivote. El número se guarda como texto, así que se compara como entero en PHP.
        $numeros = Historia::whereIn('reg_medico', $this->registrosMedicos)->pluck('numhistoria')
            ->merge(MedicoPaciente::where('medico_id', $this->medico->id)->pluck('numhistoria'))
            ->filter(fn ($n) => $n !== null && is_numeric($n))
            ->map(fn ($n) => (int) $n);

        return ((int) $numeros->max()) + 1;
    }

    private function siguienteRecipe(Consulta $consulta): int
    {
        $filtro = fn ($query) => $query->where('nrohistoria', $consulta->numhistoria)
            ->where('nroconsulta', $consulta->nroconsulta)
            ->whereIn('reg_medico', $this->registrosMedicos);

        $ultimo = max(
            (int) $filtro(Recipe::query())->max('recipe'),
            (int) $filtro(RecipeDetalle::query())->max('recipe'),
        );
        // Los récipes viejos del legado tienen `recipe` NULL (uno por consulta): cuentan como el 1.
        if ($ultimo === 0 && $filtro(Recipe::query())->exists()) {
            $ultimo = 1;
        }

        return $ultimo + 1;
    }

    private function resolverHistoria(array $change): ?Historia
    {
        if (isset($change['historia_temp_id'])) {
            $tempId = (int) $change['historia_temp_id'];
            $id = $this->historias[$tempId] ?? $this->yaCreado('historias', $tempId);
            return $id === null ? null : Historia::whereIn('reg_medico', $this->registrosMedicos)->find($id);
        }
        if (isset($change['numhistoria'])) {
            return Historia::where('numhistoria', (string) (int) $change['numhistoria'])
                ->whereIn('reg_medico', $this->registrosMedicos)
                ->first();
        }
        return null;
    }

    private function resolverConsulta(array $change): ?Consulta
    {
        $id = $change['consulta_id'] ?? null;
        if (isset($change['consulta_temp_id'])) {
            $tempId = (int) $change['consulta_temp_id'];
            $id = $this->consultas[$tempId] ?? $this->yaCreado('consultas', $tempId);
        }
        return $id === null ? null : Consulta::whereIn('reg_medico', $this->registrosMedicos)->find((int) $id);
    }

    /** @return list<array{codemedicina: string, cantidad: ?int, indicaciones: ?string, descripcion: ?string}>|null */
    private function itemsValidos($items): ?array
    {
        if (!is_array($items) || $items === []) {
            return null;
        }
        $validos = [];
        foreach (array_values($items) as $item) {
            $codigo = is_array($item) ? ($item['codemedicina'] ?? null) : null;
            if (!is_string($codigo) || $codigo === '' || mb_strlen($codigo) > 8) {
                return null;
            }
            $cantidad = $item['cantidad'] ?? null;
            $validos[] = [
                'codemedicina' => $codigo,
                'cantidad' => is_numeric($cantidad) ? (int) $cantidad : null,
                'indicaciones' => is_string($item['indicaciones'] ?? null) ? $item['indicaciones'] : null,
                'descripcion' => is_string($item['descripcion'] ?? null) ? mb_substr($item['descripcion'], 0, 200) : null,
            ];
        }
        return $validos;
    }

    private function fechaValida($valor): ?string
    {
        if (!is_string($valor)) {
            return null;
        }
        $fecha = DateTime::createFromFormat('!Y-m-d', $valor);
        return $fecha && $fecha->format('Y-m-d') === $valor ? $valor : null;
    }

    private function yaCreado(string $table, int $tempId): ?int
    {
        return SyncChange::where('table_name', $table)
            ->where('client_temp_id', $tempId)
            ->where('operation', 'created')
            ->whereIn('reg_medico', $this->registrosMedicos)
            ->value('record_id');
    }

    private function anotar(string $table, int $recordId, int $tempId, ?string $regMedico): void
    {
        SyncChange::create([
            'reg_medico' => $regMedico,
            'table_name' => $table,
            'record_id' => $recordId,
            'client_temp_id' => $tempId,
            'operation' => 'created',
            'occurred_at' => now(),
            'source' => 'mobile',
        ]);
    }

    /** La misma respuesta que en la creación original, para el reintento. */
    private function respuestaDe(string $table, int $tempId, int $recordId): ?array
    {
        if ($table === 'historias') {
            $this->historias[$tempId] = $recordId;
            $historia = Historia::find($recordId);
            return $historia ? $this->respuestaHistoria($tempId, $historia) : null;
        }
        if ($table === 'consultas') {
            $this->consultas[$tempId] = $recordId;
            $consulta = Consulta::find($recordId);
            return $consulta ? $this->respuestaConsulta($tempId, $consulta) : null;
        }
        $detalle = RecipeDetalle::find($recordId);
        if (!$detalle) {
            return null;
        }
        $filas = Recipe::where('nrohistoria', $detalle->nrohistoria)
            ->where('nroconsulta', $detalle->nroconsulta)
            ->where('recipe', $detalle->recipe)
            ->whereIn('reg_medico', $this->registrosMedicos)
            ->orderBy('orden')
            ->get();
        return $this->respuestaRecipe($tempId, $detalle, $filas);
    }

    private function respuestaHistoria(int $tempId, Historia $historia): array
    {
        return [
            'table' => 'historias',
            'temp_id' => $tempId,
            'id' => $historia->id,
            'numhistoria' => (int) $historia->numhistoria,
        ];
    }

    private function respuestaConsulta(int $tempId, Consulta $consulta): array
    {
        return [
            'table' => 'consultas',
            'temp_id' => $tempId,
            'id' => $consulta->id,
            'numhistoria' => (int) $consulta->numhistoria,
            'nroconsulta' => (int) $consulta->nroconsulta,
        ];
    }

    /** `ids`: las filas de `recipes`, en el mismo orden que los `items` que mandó el teléfono. */
    private function respuestaRecipe(int $tempId, RecipeDetalle $detalle, Collection $filas): array
    {
        return [
            'table' => 'recipes',
            'temp_id' => $tempId,
            'id' => $detalle->id,
            'recipe' => (int) $detalle->recipe,
            'ids' => $filas->pluck('id')->values()->all(),
        ];
    }
}
