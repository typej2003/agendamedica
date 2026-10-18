<?php

namespace App\Sync;

use App\Actions\Consulta\AgregarDiagnosticoALaConsulta;
use App\Actions\Consulta\AgregarMotivoALaConsulta;
use App\Actions\Consulta\CrearConsulta;
use App\Actions\Consulta\GuardarEvaluacionFisica;
use App\Actions\Consulta\GuardarRecipe;
use App\Actions\Consulta\QuitarMotivoDeLaConsulta;
use App\Actions\Pacientes\AgregarAntecedenteAlPaciente;
use App\Actions\Pacientes\AsignarHistoria;
use App\Clinica\Consulta\ExamenFisicoDeLaConsulta;
use App\Http\Requests\SyncAppDataRequest;
use App\Models\Cola;
use App\Models\Consulta;
use App\Models\Historia;
use App\Models\Medico;
use App\Models\MotivoConsulta;
use App\Models\Recipe;
use App\Models\RecipeDetalle;
use App\Models\SyncChange;
use Carbon\Carbon;
use DateTime;
use Illuminate\Support\Collection;
use InvalidArgumentException;

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
 *
 *    Las dos reglas del número que ya usaba otra superficie **no viven acá**: el de la historia está en
 *    `App\Actions\Pacientes\AsignarHistoria` (desde WEB-2.11) y el de la consulta en
 *    `App\Actions\Consulta\CrearConsulta` (desde WEB-4.1, cuando "Atender" de la web pasó a abrir la
 *    consulta). Este servicio les pasa lo que solo él sabe —la historia resuelta del lote— y anota el
 *    rastro.
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
 *
 * Paso 18.B2 suma los **motivos de una consulta**: agregar uno del catálogo
 * (`motivo_consulta_paciente`) y quitarlo ([self::quitarMotivoDeConsulta]). Las dos reglas se extrajeron
 * a `App\Actions\Consulta\` en WEB-4.2 (las comparte la consulta de la web); el catálogo en sí lo amplía
 * un endpoint online-only aparte, no esta cola.
 *
 * A3 de PLAN-MOVIL.md suma la **evaluación física** de la consulta (`examen_fisico_nuevo`), que se
 * aparta de las tres reglas de arriba en un punto: **no tiene número que asignar**, así que el teléfono
 * manda las columnas que el médico cargó y el servidor resuelve a qué fila del legado van. La regla de
 * esa escritura no vive acá (ver [self::crearExamenFisico]).
 *
 * A4a de PLAN-MOVIL.md suma el **alta de un diagnóstico de la consulta** (`diagnostico_paciente`), la otra
 * mitad del agregar/quitar que el teléfono ya tenía a medias: la baja entra desde A1 por el camino
 * genérico (`TABLAS_BORRABLES`) y el alta es una creación clínica porque la fila cuelga de la consulta,
 * igual que un motivo (ver [self::crearDiagnostico]).
 *
 * A4b suma el **alta de un antecedente del paciente** (`antece_paciente`): misma forma —alta por creación y
 * baja por A1—, pero la fila **no cuelga de la consulta** sino de la historia (ver
 * [self::crearAntecedente]).
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
        private AsignarHistoria $asignarHistoria,
        private CrearConsulta $crearConsulta,
        // Las dos reglas de los motivos de una consulta, compartidas con la web desde WEB-4.2.
        private AgregarMotivoALaConsulta $agregarMotivo,
        private QuitarMotivoDeLaConsulta $quitarMotivo,
        // La regla del récipe (el número, la cabecera y la forma de las filas), compartida con la web
        // desde WEB-5.1.
        private GuardarRecipe $guardarRecipe,
        // La regla de la evaluación física, compartida con la web desde WEB-4.3: resuelve la clave del
        // legado `(historia, consulta)`, crea la fila si falta y escribe sólo lo que cambió (A3).
        private GuardarEvaluacionFisica $guardarEvaluacionFisica,
        // El **mapa del dato** de esa evaluación: es el único lugar que sabe que la fila se busca por los
        // números de la consulta y no por su id (trampa del §7.4 de PLAN-MOVIL.md), así que el id que se
        // le devuelve al teléfono sale de acá y no de un `where` suelto.
        private ExamenFisicoDeLaConsulta $examenFisico,
        // El alta de un diagnóstico de la consulta (A4a): la regla del código del catálogo, la copia de la
        // descripción y el `orden`, en una Action de `Actions\Consulta` (la fila cuelga de la consulta).
        private AgregarDiagnosticoALaConsulta $agregarDiagnostico,
        // El alta de un antecedente del paciente (A4b): la misma clase de Action fina, pero en
        // `Actions\Pacientes` porque la fila es **del paciente** y no de la consulta.
        private AgregarAntecedenteAlPaciente $agregarAntecedente,
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
            'motivo_consulta_paciente' => $this->crearMotivoDeConsulta($change, $tempId, $rechazar),
            'examen_fisico_nuevo' => $this->crearExamenFisico($change, $tempId, $rechazar),
            'diagnostico_paciente' => $this->crearDiagnostico($change, $tempId, $rechazar),
            // A4b: el alta de un antecedente del paciente, que cuelga de la **historia**.
            'antece_paciente' => $this->crearAntecedente($change, $tempId, $rechazar),
        };
        if ($respuesta !== null) {
            $creados[] = $respuesta;
        }
    }

    /**
     * Quitar un motivo de una consulta. No toca el catálogo (como en el legado: "eliminar un motivo
     * de la consulta no lo borra del catálogo"). Deja rastro en `sync_changes` para que los demás
     * teléfonos también lo suelten. Idempotente: si ya no está, no hace nada.
     *
     * La regla **no vive acá**: es `App\Actions\Consulta\QuitarMotivoDeLaConsulta`, la misma que usa la
     * consulta de la web desde WEB-4.2. Lo único propio del sync es que restringe a los registros del
     * médico y firma el rastro como `mobile`.
     */
    public function quitarMotivoDeConsulta(int $recordId): void
    {
        $this->quitarMotivo->ejecutar($recordId, $this->registrosMedicos, 'mobile');
    }

    /**
     * "Llenar historia": le asigna número a un paciente que lo tiene de este médico sin historia. Los
     * datos de la ficha no viajan acá: ya se editan con `updated` sobre `pacientes`.
     *
     * La regla del número —el correlativo del médico, la idempotencia y el enlace de las citas que
     * estaban "sin historia"— **no vive acá**: la resuelve `AsignarHistoria`, que es la misma Action
     * que usa la web con "Pasar a cita formal" (WEB-2.11). Lo único propio del sync es el rastro: a
     * qué id temporal corresponde la historia que se acaba de devolver.
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

        try {
            $historia = $this->asignarHistoria->ejecutar($this->medico, $this->registrosMedicos, (int) $pacienteId);
        } catch (InvalidArgumentException $e) {
            $rechazar($e->getMessage());
            return null;
        }

        $this->anotar('historias', $historia->id, $tempId, $historia->reg_medico);
        $this->historias[$tempId] = $historia->id;

        return $this->respuestaHistoria($tempId, $historia);
    }

    /**
     * Abrir una consulta al atender. Siempre crea una nueva: la pregunta "¿re-atender o consulta
     * nueva?" la resuelve el teléfono (re-atender reusa la del día sin mandar nada acá).
     *
     * La regla del **número** —la última de esa historia + 1— **no vive acá**: la resuelve
     * `App\Actions\Consulta\CrearConsulta`, que es la misma Action que usa la web al "Atender"
     * (PLAN-WEB.md, R3; el mismo camino que hizo `AsignarHistoria` en WEB-2.11). Lo único propio del
     * sync es el rastro: a qué id temporal corresponde la consulta que se acaba de crear.
     */
    private function crearConsulta(array $change, int $tempId, callable $rechazar): ?array
    {
        $historia = $this->resolverHistoria($change);
        if (!$historia) {
            $rechazar('La consulta no corresponde a una historia de este médico.');
            return null;
        }

        $fecha = $this->fechaValida($change['fecha'] ?? null) ?? now()->toDateString();

        $consulta = $this->crearConsulta->ejecutar($historia, Carbon::parse($fecha));

        $this->anotar('consultas', $consulta->id, $tempId, $consulta->reg_medico);
        $this->consultas[$tempId] = $consulta->id;

        return $this->respuestaConsulta($tempId, $consulta);
    }

    /**
     * Guardar un récipe: una cabecera en `recipe_detalle` y una fila de `recipes` por medicamento,
     * todas con el mismo número de récipe dentro de la consulta. Viaja como **una** creación con sus
     * `items` y no como una por fila: un récipe a medio crear (la mitad de los medicamentos) no tiene
     * sentido ni para imprimir ni para el escritorio.
     *
     * La regla de escritura —el número que sigue, la cabecera con `fe_vence = 30` y la forma de las
     * filas— **no vive acá**: es `App\Actions\Consulta\GuardarRecipe`, la misma que usa la sección de
     * récipes de la consulta de la web desde WEB-5.1 (R3). Lo único propio del sync es lo que valida del
     * lote (`itemsValidos`), la fecha por defecto y el rastro: el `created` de la **cabecera** con el id
     * temporal del lote (`anotar`), que es lo que le permite al teléfono soltar su fila provisional y al
     * reemplazo de `recipe_detalle` del escritorio conservar la cabecera. Por eso se llama sin `$origen`:
     * la Action no anota nada.
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

        $resultado = $this->guardarRecipe->ejecutar($consulta, $items, $fecha, null, null, null);

        $this->anotar('recipes', $resultado->cabecera->id, $tempId, $resultado->cabecera->reg_medico);

        return $this->respuestaRecipe($tempId, $resultado->cabecera, $resultado->filas);
    }

    /**
     * Agregar un motivo a una consulta. Cuelga de la consulta (existente o del mismo lote) y de un
     * motivo que **ya está** en el catálogo (`codemotivo`): el catálogo no se crea por acá sino con
     * `POST /app/motivos-consulta` (online-only, ver `MotivoConsultaController`).
     *
     * Las dos reglas —"el motivo tiene que estar en el catálogo de este médico" y "agregarlo dos veces
     * devuelve la fila que ya estaba"— **no viven acá**: las resuelve `AgregarMotivoALaConsulta`, la
     * misma Action que usa la consulta de la web desde WEB-4.2. Lo único propio del sync es el rastro
     * del **id temporal** del lote (`anotar`), que es lo que le permite al teléfono soltar su fila
     * provisional y reintentar sin duplicar.
     */
    private function crearMotivoDeConsulta(array $change, int $tempId, callable $rechazar): ?array
    {
        $consulta = $this->resolverConsulta($change);
        if (!$consulta) {
            $rechazar('El motivo no corresponde a una consulta de este médico.');
            return null;
        }

        $motivo = $this->resolverMotivoDelCatalogo($change);
        if (!$motivo) {
            $rechazar('El motivo no está en el catálogo de este médico.');
            return null;
        }

        [$fila] = $this->agregarMotivo->ejecutar($consulta, $motivo, 'mobile');

        $this->anotar('motivo_consulta_paciente', $fila->id, $tempId, $fila->reg_medico);

        return $this->respuestaMotivoDeConsulta($tempId, $fila->id);
    }

    /**
     * La **evaluación física** de una consulta cargada sin conexión (A3 de PLAN-MOVIL.md).
     *
     * Es la única creación clínica que **trae columnas**: las otras tres las numera el servidor y sólo
     * mandan de qué cuelgan, pero la evaluación física no tiene número propio —cuelga de la consulta y su
     * clave en el legado es `(historia, consulta)`—, así que lo que viaja es lo que el médico cargó.
     *
     * La regla de escritura **no vive acá**: es `GuardarEvaluacionFisica`, la misma Action que usa la
     * evaluación física de la consulta de la web desde WEB-4.3 (R3). Esa Action ya sabe las tres cosas
     * difíciles: buscar la fila por la clave del legado —⚠️ `examen_fisico_nuevo.consulta` es el
     * **número** de la consulta (`consultas.nroconsulta`), **no** su id, trampa del §7.4 de
     * PLAN-MOVIL.md—, crear la fila si no existe y escribir **sólo lo que cambió** con su rastro en
     * `sync_changes`. Lo único propio del sync es el `created` con el id temporal del lote (`anotar`),
     * que es lo que le permite al teléfono soltar su fila provisional y reintentar sin duplicar.
     *
     * Los valores salen de `columns` (el mismo campo que usan las creaciones de citas y pacientes),
     * filtrados por `WRITABLE_COLUMNS['examen_fisico_nuevo']`: lo que no esté en esa lista se descarta en
     * silencio, igual que en las ediciones. Eso deja afuera, entre otros, los resúmenes narrativos
     * `total_f`/`total_g`, que arma el escritorio y tienen texto clínico real (§7.6).
     */
    private function crearExamenFisico(array $change, int $tempId, callable $rechazar): ?array
    {
        $consulta = $this->resolverConsulta($change);
        if (!$consulta) {
            $rechazar('La evaluación física no corresponde a una consulta de este médico.');
            return null;
        }

        $this->guardarEvaluacionFisica->ejecutar($consulta, $this->valoresDelCliente($change), null, 'mobile');

        // El id real de la fila, que es el `remote_id` que el teléfono guarda para seguir editándola. Si
        // la creación no trajo ninguna columna **de `examen_fisico_nuevo`** —por ejemplo, sólo medidas,
        // que viven en `consultas`— la Action no la creó, porque no había nada que escribir en ella. Se
        // crea vacía con la clave del legado, que es exactamente lo que hace el escritorio al abrir la
        // consulta (`w_consulta_principal.srw:5019-5029`), para que el teléfono siempre reciba un id al
        // cual apuntar: si no, sus ediciones siguientes se descartarían en silencio por no encontrar la
        // fila.
        $fila = $this->examenFisico->filaDe($consulta);
        if ($fila === null) {
            $fila = $this->examenFisico->filaParaEscribir($consulta);
            $fila->save();
        }

        $this->anotar('examen_fisico_nuevo', $fila->id, $tempId, $consulta->reg_medico);

        return $this->respuestaExamen($tempId, $fila->id);
    }

    /**
     * El **alta de un diagnóstico de la consulta** cargado sin conexión (A4a de PLAN-MOVIL.md).
     *
     * La baja ya entraba desde A1 por el camino genérico de `TABLAS_BORRABLES`; lo que faltaba era el
     * alta, y va como creación clínica porque la fila **cuelga de la consulta** (como un motivo) y no
     * tiene número propio: el teléfono manda el `codediagnostico` del catálogo y el servidor resuelve los
     * números de la consulta, el orden y la copia de la descripción.
     *
     * La regla de escritura **no vive acá**: es `AgregarDiagnosticoALaConsulta`, que valida el código
     * contra el catálogo `diagnosticos` del médico, copia la descripción como el `INSERT` del escritorio y
     * no duplica si esa consulta ya tiene el código (devuelve la fila que estaba). El catálogo se consulta
     * con `DiagnosticosDeLaConsulta` —el mapa del dato— y no con un `where` suelto.
     *
     * Lo único propio del sync es el rastro con el **id temporal** del lote (`anotar`), que es lo que le
     * permite al teléfono soltar su fila provisional y reintentar sin duplicar.
     *
     * ⚠️ `nrohistoria` y `nroconsulta` son los **números** de la consulta (`consultas.numhistoria` y
     * `consultas.nroconsulta`), no el id de `consultas` (§7.4 de PLAN-MOVIL.md).
     */
    private function crearDiagnostico(array $change, int $tempId, callable $rechazar): ?array
    {
        $consulta = $this->resolverConsulta($change);
        if (!$consulta) {
            $rechazar('El diagnóstico no corresponde a una consulta de este médico.');
            return null;
        }

        // El código viaja al mismo nivel que las referencias (como `codemotivo`) o dentro de `columns`
        // (la forma de las creaciones con datos): se aceptan las dos, igual que en A3.
        $codigo = $this->campoDelCambio($change, 'codediagnostico');
        $codigo = is_string($codigo) ? trim($codigo) : '';
        if ($codigo === '') {
            $rechazar('El diagnóstico no indica el código del catálogo.');
            return null;
        }

        // El detalle es opcional: si no viene, la Action copia la descripción del catálogo.
        $detalle = $this->campoDelCambio($change, 'detalle_diagnostco')
            ?? $this->campoDelCambio($change, 'detalle');
        $detalle = is_string($detalle) ? $detalle : null;

        $fila = $this->agregarDiagnostico->ejecutar($consulta, $codigo, $detalle, 'mobile');

        if ($fila === null) {
            // No se escribe nada: una fila sin nombre no la puede leer ni el escritorio ni la web.
            $rechazar('El diagnóstico no está en el catálogo de este médico.');
            return null;
        }

        $this->anotar('diagnostico_paciente', $fila->id, $tempId, $fila->reg_medico);

        return $this->respuestaDiagnostico($tempId, $fila->id);
    }

    /**
     * El **alta de un antecedente del paciente** cargado sin conexión (A4b de PLAN-MOVIL.md).
     *
     * La baja ya entraba desde A1 por el camino genérico de `TABLAS_BORRABLES`; lo que faltaba era el
     * alta, que va como creación clínica aunque **no cuelgue de la consulta**: `antece_paciente` es del
     * paciente y se llavea por `numhistoria` + `codeantecedente` (el mapa del dato es
     * `AntecedentesDelPaciente`). Por eso lo que resuelve esta rama es la **historia** —real o creada en
     * este mismo lote—, no una consulta.
     *
     * La regla de escritura **no vive acá**: es `AgregarAntecedenteAlPaciente`, que valida el código
     * contra el catálogo `antecedentes` del médico, copia el nombre y el tipo como el `INSERT` del
     * escritorio y no duplica si ese paciente ya tiene el código (devuelve la fila que estaba).
     *
     * Lo único propio del sync es el rastro con el **id temporal** del lote (`anotar`), que es lo que le
     * permite al teléfono soltar su fila provisional y reintentar sin duplicar.
     */
    private function crearAntecedente(array $change, int $tempId, callable $rechazar): ?array
    {
        // La historia: por su número si el paciente ya existe en el servidor, o por `historia_temp_id` si
        // se llenó en este mismo lote (el helper que ya usan la consulta y el récipe).
        $historia = $this->resolverHistoria($change);
        if (! $historia) {
            $rechazar('El antecedente no corresponde a una historia de este médico.');
            return null;
        }

        // El código viaja al mismo nivel que las referencias (como `codemotivo`) o dentro de `columns`
        // (la forma de las creaciones con datos): se aceptan las dos, igual que en A3 y A4a.
        $codigo = $this->campoDelCambio($change, 'codeantecedente');
        $codigo = is_string($codigo) ? trim($codigo) : '';
        if ($codigo === '') {
            $rechazar('El antecedente no indica el código del catálogo.');
            return null;
        }

        $fila = $this->agregarAntecedente->ejecutar(
            (string) $historia->reg_medico,
            (int) $historia->numhistoria,
            $codigo,
            $this->textoDelCambio($change, 'detalles'),
            $this->textoDelCambio($change, 'tipo2'),
            $this->textoDelCambio($change, 'descripcion'),
            $this->textoDelCambio($change, 'tipo'),
            'mobile',
        );

        if ($fila === null) {
            // No se escribe nada: una fila sin nombre ni tipo no la puede leer ni el escritorio ni la web.
            $rechazar('El antecedente no está en el catálogo de este médico.');
            return null;
        }

        $this->anotar('antece_paciente', $fila->id, $tempId, $fila->reg_medico);

        return $this->respuestaAntecedente($tempId, $fila->id);
    }

    /**
     * Un campo de texto de la creación (`detalles`, `tipo2`, …): suelto o dentro de `columns`, como
     * `campoDelCambio`, pero sólo si es texto. Lo que no es texto se descarta y la Action pone su
     * valor por defecto.
     */
    private function textoDelCambio(array $change, string $campo): ?string
    {
        $valor = $this->campoDelCambio($change, $campo);

        return is_string($valor) ? $valor : null;
    }

    /**
     * Un campo de la creación que puede venir suelto (al mismo nivel que `consulta_id`, como
     * `codemotivo` y `codediagnostico`) o dentro de `columns` (donde viajan las columnas de una tabla).
     * Gana `columns`, que es la forma explícita.
     */
    private function campoDelCambio(array $change, string $campo)
    {
        $columnas = (array) ($change['columns'] ?? []);

        if (array_key_exists($campo, $columnas)) {
            return $columnas[$campo];
        }

        return $change[$campo] ?? null;
    }

    /**
     * Las columnas de la evaluación física que mandó el cliente, ya filtradas por la lista blanca.
     *
     * Se leen de `columns` —la forma que tienen todas las creaciones con datos— y también sueltas en la
     * misma creación, porque lo que dice de qué cuelga (`consulta_id`/`consulta_temp_id`) viaja al mismo
     * nivel: así el teléfono no necesita dos formas de cuerpo para lo mismo. En los dos casos la lista
     * blanca es la que decide: `WRITABLE_COLUMNS['examen_fisico_nuevo']` abre la tabla, no sus campos.
     *
     * @return array<string,mixed>
     */
    private function valoresDelCliente(array $change): array
    {
        $columnas = (array) ($change['columns'] ?? []);

        $valores = [];
        foreach (SyncAppDataRequest::WRITABLE_COLUMNS['examen_fisico_nuevo'] as $columna) {
            if (array_key_exists($columna, $columnas)) {
                $valores[$columna] = $columnas[$columna];
            } elseif (array_key_exists($columna, $change)) {
                $valores[$columna] = $change[$columna];
            }
        }

        return $valores;
    }

    /** El motivo del catálogo al que apunta la creación, o null si no existe en este tenant. */
    private function resolverMotivoDelCatalogo(array $change): ?MotivoConsulta
    {
        $codigo = $change['codemotivo'] ?? null;
        if (!is_string($codigo) || trim($codigo) === '') {
            return null;
        }

        return MotivoConsulta::whereIn('reg_medico', $this->registrosMedicos)
            ->where('codemotivo', trim($codigo))
            ->first();
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
        if ($table === 'motivo_consulta_paciente') {
            // Aunque la fila ya no exista (otro teléfono la quitó después), el teléfono necesita
            // la respuesta para soltar su id temporal: el `eliminados` siguiente la limpia.
            return $this->respuestaMotivoDeConsulta($tempId, $recordId);
        }
        if ($table === 'examen_fisico_nuevo') {
            // Igual que el motivo: el reintento de una creación ya aplicada devuelve el **mismo id** de
            // la fila (`examen_fisico_nuevo.id`), sin volver a escribir ni duplicar. La fila se busca por
            // el id que anotó el rastro, no por la clave del legado: es el id que el teléfono ya guardó.
            return $this->respuestaExamen($tempId, $recordId);
        }
        if ($table === 'diagnostico_paciente') {
            // Igual que el motivo y el examen: el reintento devuelve el **mismo id** de la fila, sin
            // volver a escribir ni duplicar. La fila se busca por el id que anotó el rastro (es el que el
            // teléfono ya guardó), no por la clave del legado.
            return $this->respuestaDiagnostico($tempId, $recordId);
        }
        if ($table === 'antece_paciente') {
            // Igual que las anteriores: el reintento de un alta de antecedente devuelve el mismo id.
            return $this->respuestaAntecedente($tempId, $recordId);
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

    private function respuestaMotivoDeConsulta(int $tempId, int $id): array
    {
        return ['table' => 'motivo_consulta_paciente', 'temp_id' => $tempId, 'id' => $id];
    }

    /**
     * La respuesta de la evaluación física es **sólo su id**: es lo único que el teléfono necesita para
     * reemplazar el id provisional de su fila local. El número de historia y el de la consulta no hacen
     * falta porque la fila cuelga de una consulta que el teléfono ya tiene (o que recibió en este lote).
     */
    private function respuestaExamen(int $tempId, int $id): array
    {
        return ['table' => 'examen_fisico_nuevo', 'temp_id' => $tempId, 'id' => $id];
    }

    /**
     * La respuesta del alta de un diagnóstico es **sólo su id**, como la de la evaluación física: la fila
     * cuelga de una consulta que el teléfono ya tiene (o recibió en este lote) y su clave del legado son
     * los números de esa consulta, no datos propios que el teléfono necesite.
     */
    private function respuestaDiagnostico(int $tempId, int $id): array
    {
        return ['table' => 'diagnostico_paciente', 'temp_id' => $tempId, 'id' => $id];
    }

    /**
     * La respuesta del alta de un antecedente es **sólo su id**, como la del diagnóstico y la del examen:
     * la fila se llavea por el número de historia, que el teléfono ya tiene en su copia local.
     */
    private function respuestaAntecedente(int $tempId, int $id): array
    {
        return ['table' => 'antece_paciente', 'temp_id' => $tempId, 'id' => $id];
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
