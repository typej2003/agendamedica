<?php

namespace App\Sync;

use App\Actions\Consulta\AgregarDiagnosticoALaConsulta;
use App\Actions\Consulta\AgregarMotivoALaConsulta;
use App\Actions\Consulta\CrearConsulta;
use App\Actions\Consulta\GuardarEcoGinecologico;
use App\Actions\Consulta\GuardarEcoAbdominal;
use App\Actions\Consulta\GuardarEcoCardiogramaFetal;
use App\Actions\Consulta\GuardarEcoDoppler;
use App\Actions\Consulta\GuardarEcoMamario;
use App\Actions\Consulta\GuardarEcoObstetrico;
use App\Actions\Consulta\GuardarEcoObstetricoSegundo;
use App\Actions\Consulta\GuardarEcoObstetricoTercero;
use App\Actions\Consulta\GuardarEvaluacionFisica;
use App\Actions\Consulta\GuardarRecipe;
use App\Actions\Consulta\GuardarRecipeDeLaPareja;
use App\Actions\Consulta\QuitarMotivoDeLaConsulta;
use App\Actions\Pacientes\AgregarAntecedenteAlPaciente;
use App\Actions\Pacientes\AsignarHistoria;
use App\Clinica\Consulta\EcoAbdominal;
use App\Clinica\Consulta\EcoCardiogramaFetal;
use App\Clinica\Consulta\EcoDoppler;
use App\Clinica\Consulta\EcoGinecologico;
use App\Clinica\Consulta\EcoMamario;
use App\Clinica\Consulta\EcoObstetrico;
use App\Clinica\Consulta\EcoObstetricoSegundo;
use App\Clinica\Consulta\EcoObstetricoTercero;
use App\Clinica\Consulta\EstudioEcografico;
use App\Clinica\Consulta\ExamenFisicoDeLaConsulta;
use App\Clinica\FechaDelConsultorio;
use App\Http\Requests\SyncAppDataRequest;
use App\Models\Cola;
use App\Models\Consulta;
use App\Models\Historia;
use App\Models\Medico;
use App\Models\MotivoConsulta;
use App\Models\Recipe;
use App\Models\RecipeDetalle;
use App\Models\RecipePareja;
use App\Models\SyncChange;
use Carbon\Carbon;
use DateTime;
use Illuminate\Database\Eloquent\Model;
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
 *
 * A4c suma las **columnas clínicas de la consulta al crearla** (ver [self::crearConsulta]): el "Atender" del
 * teléfono abre la consulta **sin conexión** y el médico escribe enfermedad actual, evolución, tratamiento y
 * la antropometría **antes** de que la consulta exista en el servidor, así que esas columnas viajan en la
 * misma creación —todavía no hay `remote_id` con el que mandarlas como `updated`—. Si el servidor las
 * ignorara, la consulta nacería vacía y el delta siguiente, que empareja por `remote_id`, pisaría la copia
 * local con `null`: se perdería lo que el médico escribió.
 *
 * EC-A3 de PLAN-MOVIL.md suma los **cuatro estudios ecográficos** —pélvico, obstétrico del 1.er trimestre y
 * los del 2.º y el 3.º— (ver [self::crearEco]): el teléfono los carga y los corrige sin conexión, sobre una
 * fila que puede no existir todavía en el servidor. La regla de escritura no vive acá sino en la Action de
 * la web de cada estudio, y las columnas aceptadas no son una lista blanca propia sino **el mapa del dato**
 * de cada uno (`SyncAppDataRequest::columnasEditables`).
 */
class CreacionesClinicas
{
    /**
     * Las columnas de `consultas` que son **números** (la antropometría y los signos). Las demás de la
     * lista blanca son texto. Se guardan como número, no como el string que manda el teléfono: el modelo
     * las castea a `double` y una columna numérica con `"70"` complica las comparaciones del sync.
     *
     * @var list<string>
     */
    private const COLUMNAS_NUMERICAS_DE_LA_CONSULTA = [
        'peso', 'talla', 'fc', 'pp', 'circcefalica', 'circtoraxica', 'circabdominal',
    ];

    /**
     * El **único** rechazo de las Actions de los ecos del que el sync se aparta: el estudio «sin datos», que
     * para la web significa «no hay hoja que imprimir» y para el teléfono **no** es un motivo para perder lo
     * que el médico escribió (ver [self::crearEco]).
     *
     * Se compara el texto exacto para no tragarse otros rechazos de la misma Action (hoy no hay ninguno, pero
     * una validación nueva tiene que seguir llegando al teléfono como `rechazados`, no crear una fila). El
     * texto lo lanzan `GuardarEcoGinecologico` y `GuardarEcoObstetricoBase`: si cambia allá, este catch deja de
     * reconocerlo y el caso vuelve a rechazarse —por eso el test lo fija de punta a punta—.
     */
    private const ECO_SIN_DATOS = 'El eco necesita algún dato: carga lo que haya dado el estudio.';

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
        // Los **cuatro estudios ecográficos** (EC-A3): la regla de guardado de cada uno es la Action de su
        // pantalla de la web —la misma que resolvió el mapa, la clave del legado, el "sólo lo que cambió" y
        // el `-1`/`none` = sin dato—, así que acá no se reimplementa nada. El **mapa del dato** de cada uno se
        // resuelve en [self::crearEco] (es una lectura sin estado y son cuatro clases más: no engordan este
        // constructor, que ya lleva las once reglas de arriba).
        private GuardarEcoGinecologico $guardarEcoPelvico,
        private GuardarEcoObstetrico $guardarEcoObstetrico,
        private GuardarEcoObstetricoSegundo $guardarEcoObstetricoSegundo,
        private GuardarEcoObstetricoTercero $guardarEcoObstetricoTercero,
        // Los **otros cuatro estudios** (ola §10 de PLAN-MOVIL.md): el Doppler perinatal, el mamario, el
        // abdominal y el ecocardiograma fetal. Cada uno con la Action de su pantalla de la web.
        private GuardarEcoDoppler $guardarEcoDoppler,
        private GuardarEcoMamario $guardarEcoMamario,
        private GuardarEcoAbdominal $guardarEcoAbdominal,
        private GuardarEcoCardiogramaFetal $guardarEcoCardiogramaFetal,
        // La regla del **récipe de la pareja** (ola §9 de PLAN-MOVIL.md), compartida con la web desde
        // WEB-5.1b: una por consulta, sin cabecera, y crea o corrige en el lugar comparando por
        // `codemedicina`. Es la misma Action que usa la pestaña de la consulta de la web.
        private GuardarRecipeDeLaPareja $guardarRecipeDeLaPareja,
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
            // A4c: el reintento de una consulta que ya se aceptó **también escribe sus columnas**. Si el
            // teléfono perdió la respuesta, la cola local ya quedó vacía y esos valores no volverían a
            // viajar: sin esto, lo que el médico escribió sin conexión no llegaría nunca al servidor.
            if ($table === 'consultas') {
                $this->completarConsulta($yaCreado, $change);
            }

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
            // Ola §9: el récipe de la pareja, que cuelga de la consulta como el del paciente pero no tiene
            // número ni cabecera (una por consulta).
            'recipes_pareja' => $this->crearRecipeDeLaPareja($change, $tempId, $rechazar),
            'motivo_consulta_paciente' => $this->crearMotivoDeConsulta($change, $tempId, $rechazar),
            'examen_fisico_nuevo' => $this->crearExamenFisico($change, $tempId, $rechazar),
            'diagnostico_paciente' => $this->crearDiagnostico($change, $tempId, $rechazar),
            // A4b: el alta de un antecedente del paciente, que cuelga de la **historia**.
            'antece_paciente' => $this->crearAntecedente($change, $tempId, $rechazar),
            // EC-A3: el alta de cada uno de los **cuatro estudios ecográficos**, que cuelgan de la consulta.
            'eco_pelvico' => $this->crearEco($change, $tempId, $rechazar),
            'eco_obstetrico' => $this->crearEco($change, $tempId, $rechazar),
            'eco_obstetrico_tercer_2' => $this->crearEco($change, $tempId, $rechazar),
            'eco_obstetrico_tercer' => $this->crearEco($change, $tempId, $rechazar),
            // Ola §10: los otros cuatro estudios, con el mismo camino (la Action de su pantalla de la web).
            'eco_doppler' => $this->crearEco($change, $tempId, $rechazar),
            'ultra_mama' => $this->crearEco($change, $tempId, $rechazar),
            'ultra_abdominal' => $this->crearEco($change, $tempId, $rechazar),
            'ecocadiograma_fetal' => $this->crearEco($change, $tempId, $rechazar),
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
            $historia = $this->asignarHistoria->ejecutar($this->medico, $this->regMedicoDelSync(), (int) $pacienteId);
        } catch (InvalidArgumentException $e) {
            $rechazar($e->getMessage());
            return null;
        }

        $this->anotar('historias', $historia->id, $tempId, $historia->reg_medico);
        $this->historias[$tempId] = $historia->id;

        return $this->respuestaHistoria($tempId, $historia);
    }

    /**
     * El consultorio (tenant) con el que el sync del móvil numera las historias: el `reg_medico` de la
     * cuenta o, si no lo tiene, el primero de sus registros. Es **el mismo criterio** con el que
     * `SyncAppDataController::crearPaciente` da de alta al paciente: si los dos no coincidieran, el
     * paciente quedaría vinculado a un consultorio y su historia en otro (Fase 1 del cambio de
     * `reg_medico`: el vínculo es del tenant, no del médico).
     */
    private function regMedicoDelSync(): string
    {
        return (string) ($this->medico->reg_medico ?: ($this->registrosMedicos[0] ?? ''));
    }

    /**
     * Abrir una consulta al atender. Siempre crea una nueva: la pregunta "¿re-atender o consulta
     * nueva?" la resuelve el teléfono (re-atender reusa la del día sin mandar nada acá).
     *
     * La regla del **número** —la última de esa historia + 1— **no vive acá**: la resuelve
     * `App\Actions\Consulta\CrearConsulta`, que es la misma Action que usa la web al "Atender"
     * (PLAN-WEB.md, R3; el mismo camino que hizo `AsignarHistoria` en WEB-2.11). Lo único propio del
     * sync es el rastro: a qué id temporal corresponde la consulta que se acaba de crear.
     *
     * **Y el doctor que la atiende** (2026-10-20): igual que la web, se le pasa a la Action el médico
     * autenticado del sync —el dueño de la sesión del teléfono, que es el que está atendiendo—, así que la
     * consulta nace con `medico_id` y con su clave legacy en `medico` en vez de nulas. Es lo que hace que
     * `App\Clinica\Impresion\MedicoQueFirma` firme sus hojas con ese doctor sin depender del médico del
     * contexto (el respaldo). El endpoint no cambia: el teléfono sigue mandando lo mismo.
     *
     * **A4c: la creación trae las columnas clínicas.** El "Atender" del teléfono crea la consulta
     * **sin conexión**, y el médico escribe la enfermedad actual, la evolución, el tratamiento o la
     * antropometría **antes** de que esa consulta exista en el servidor. El teléfono no puede mandarlas
     * como `updated` —todavía no hay `remote_id`—, así que viajan en la creación. Acá se aplican **después**
     * de que la Action asigne `reg_medico`, `numhistoria`, `nroconsulta` y `fecha`: esas cuatro columnas las
     * resuelve el servidor y **no** se aceptan del cliente (el número de consulta es un correlativo y el
     * tenant sale del médico autenticado), por eso la lista blanca es `WRITABLE_COLUMNS['consultas']` y no
     * todo lo que venga en `columns`.
     *
     * Si el servidor las ignorara, la consulta nacería vacía y el delta siguiente —que empareja por
     * `remote_id`— pisaría la copia local con `null`: se perdería el texto que escribió el médico. El
     * reintento de una creación ya aceptada también las aplica ([self::completarConsulta]).
     */
    private function crearConsulta(array $change, int $tempId, callable $rechazar): ?array
    {
        $historia = $this->resolverHistoria($change);
        if (!$historia) {
            $rechazar('La consulta no corresponde a una historia de este médico.');
            return null;
        }

        $fecha = $this->fechaValida($change['fecha'] ?? null) ?? now()->toDateString();

        // El doctor es el médico autenticado del sync: la consulta queda sellada con él (`medico_id` y su
        // clave legacy en `medico`), igual que la que abre la web al "Atender".
        $consulta = $this->crearConsulta->ejecutar($historia, Carbon::parse($fecha), $this->medico);

        // Las columnas del cliente, al crear la fila. Va después de la Action para no pisar el número ni
        // el tenant, y no deja rastro por columna: la fila es nueva y su `created` (el de `anotar`, más
        // abajo) ya hace que el delta la devuelva completa.
        $columnas = $this->columnasDelCliente($change, 'consultas');
        if ($columnas !== []) {
            foreach ($columnas as $columna => $valor) {
                $consulta->{$columna} = $this->valorDeLaConsulta($columna, $valor);
            }
            $consulta->save();
        }

        $this->anotar('consultas', $consulta->id, $tempId, $consulta->reg_medico);
        $this->consultas[$tempId] = $consulta->id;

        return $this->respuestaConsulta($tempId, $consulta);
    }

    /**
     * **A4c: aplicar las columnas de una consulta ya creada** (el reintento de una creación aceptada).
     *
     * El caso que resuelve: el teléfono mandó la creación con lo que el médico escribió, el servidor la
     * aceptó, pero la respuesta se perdió. El reintento vuelve con el mismo `temp_id` y las columnas; sin
     * esto, la creación temprana las descartaría (el rastro ya existe y se contesta lo mismo) y el texto
     * clínico no llegaría nunca, porque la cola local ya está vacía.
     *
     * Se escribe **sólo lo que cambió**, con el rastro `updated` por columna y el **last-write-wins** del
     * sync (`RegistroDeCambios::permite`), igual que cualquier edición de A1: si otro equipo escribió esa
     * columna después, el reintento no la pisa. El rastro va con `source = mobile`, que es quien escribe.
     */
    private function completarConsulta(int $id, array $change): void
    {
        $columnas = $this->columnasDelCliente($change, 'consultas');
        if ($columnas === []) {
            return;
        }

        // La fila es de este médico: `yaCreado` ya la acotó a sus registros, pero se vuelve a comprobar
        // para no escribir por un id que no corresponde al tenant.
        $consulta = Consulta::whereIn('reg_medico', $this->registrosMedicos)->find($id);
        if ($consulta === null) {
            return;
        }

        $cuando = Carbon::now();
        $cambios = [];

        foreach ($columnas as $columna => $valor) {
            $nuevo = $this->valorDeLaConsulta($columna, $valor);

            if ($consulta->{$columna} === $nuevo) {
                continue;
            }

            if (! RegistroDeCambios::permite('consultas', (int) $consulta->id, $columna, $cuando)) {
                // Una edición igual o más nueva ya tocó esa columna: no se pisa (y las demás siguen).
                continue;
            }

            $cambios[$columna] = $nuevo;
        }

        if ($cambios === []) {
            return;
        }

        foreach ($cambios as $columna => $valor) {
            $consulta->{$columna} = $valor;
        }
        $consulta->save();

        foreach ($cambios as $columna => $_) {
            // El rastro guarda lo que quedó en la columna, no lo que llegó del cliente.
            RegistroDeCambios::registrar(
                'consultas',
                (int) $consulta->id,
                $consulta->reg_medico,
                $columna,
                $consulta->{$columna},
                $cuando,
                'mobile',
            );
        }
    }

    /**
     * El valor de una columna de `consultas` ya listo para la columna: número para las medidas, texto
     * limpio para lo demás. Mismo criterio que `GuardarEvaluacionFisica`: un valor no numérico en una
     * medida se guarda como `null` y no como 0 —un 0 silencioso parece un dato—, y el `""` o los espacios
     * son el mismo estado que `null`.
     */
    private function valorDeLaConsulta(string $columna, $valor)
    {
        if (! is_scalar($valor)) {
            return null;
        }

        if (in_array($columna, self::COLUMNAS_NUMERICAS_DE_LA_CONSULTA, true)) {
            return is_numeric($valor) ? (float) $valor : null;
        }

        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
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
     * El **récipe de la pareja** de una consulta, cargado sin conexión (ola §9 de PLAN-MOVIL.md).
     *
     * La pareja es **una por consulta** y no tiene cabecera ni número: cada medicamento es una fila de
     * `recipes_pareja` con la cédula y el nombre de la pareja **repetidos en todas**. Viaja como **una**
     * creación con sus `items` —la mitad de un récipe no sirve para imprimir— y con `cedula` y `nombre` al
     * mismo nivel que `fecha` e `items`: son la cabecera del récipe, no columnas de una tabla con `columns`
     * (si se mandaran ahí, el controlador las descartaría en silencio).
     *
     * La regla de escritura **no vive acá**: es `App\Actions\Consulta\GuardarRecipeDeLaPareja` (WEB-5.1b),
     * la misma que usa la pestaña de la consulta de la web. Esa Action crea cuando la consulta no tiene y
     * **corrige en el lugar** cuando ya tiene (compara por `codemedicina`, escribe sólo lo que cambió y quita
     * las filas que ya no vienen), así que un reintento de la misma creación no duplica nada. Se llama sin
     * `$origen` —igual que `crearRecipe`—: el rastro que interesa es el `created` del id temporal del lote
     * (`anotar`), que es lo que le permite al teléfono soltar su fila provisional y al reemplazo de la tabla
     * del escritorio conservar estas filas.
     *
     * ⚠️ El rastro se anota con el id de la **primera** fila: la tabla no tiene una fila cabecera que
     * represente al récipe, y lo único que el teléfono necesita es un id con el que reconocerlo al reintentar.
     * Una corrección de una pareja que ya existía no devuelve filas creadas (`filas` viene vacía), así que el
     * id se resuelve leyendo la primera fila que hay.
     */
    private function crearRecipeDeLaPareja(array $change, int $tempId, callable $rechazar): ?array
    {
        $consulta = $this->resolverConsulta($change);
        if (!$consulta) {
            $rechazar('El récipe de la pareja no corresponde a una consulta de este médico.');
            return null;
        }

        $items = $this->itemsValidos($change['items'] ?? null);
        if ($items === null) {
            $rechazar('El récipe de la pareja no tiene medicamentos válidos.');
            return null;
        }

        try {
            $resultado = $this->guardarRecipeDeLaPareja->ejecutar(
                $consulta,
                (string) ($change['cedula'] ?? ''),
                (string) ($change['nombre'] ?? ''),
                $items,
                null,
                null,
            );
        } catch (InvalidArgumentException $e) {
            // Los topes del esquema (cédula de 10, nombre de 200) y la pareja sin datos: es el rechazo de la
            // Action —para esa creación—, no un error del lote.
            $rechazar($e->getMessage());
            return null;
        }

        $fila = $resultado->filas->first()
            ?? RecipePareja::where('reg_medico', $consulta->reg_medico)
                ->where('nrohistoria', (int) $consulta->numhistoria)
                ->where('nroconsulta', (int) $consulta->nroconsulta)
                ->orderBy('orden')
                ->orderBy('id')
                ->first();

        if ($fila === null) {
            $rechazar('El récipe de la pareja quedó sin medicamentos.');
            return null;
        }

        $this->anotar('recipes_pareja', (int) $fila->id, $tempId, (string) $fila->reg_medico);

        return $this->respuestaRecipeDeLaPareja($tempId, (int) $fila->id);
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
     * El **alta de un estudio ecográfico** cargado sin conexión (EC-A3 de PLAN-MOVIL.md): el pélvico, el
     * obstétrico del 1.er trimestre o el del 2.º o el 3.º.
     *
     * Es una creación clínica aunque **no numere nada**: la fila cuelga de la consulta con la clave del
     * legado —⚠️ `numhistoria` + `nroconsulta` en `eco_pelvico` y `historia` + `consulta` en las tres
     * obstétricas, donde `consulta` es el **número** de la consulta y no su id (§7.4 de PLAN-MOVIL.md)— y
     * puede no existir todavía en el servidor. Por eso el teléfono manda las columnas que el médico cargó,
     * como en la evaluación física (A3).
     *
     * La regla de escritura **no vive acá**: es la Action de la web de ese estudio (`GuardarEcoGinecologico`,
     * `GuardarEcoObstetrico`, `GuardarEcoObstetricoSegundo`, `GuardarEcoObstetricoTercero`), que ya sabe
     * buscar la fila por la clave del legado, crearla si falta, escribir **sólo lo que cambió** con su
     * last-write-wins, dejar el rastro por columna, completar la fecha con hoy y rechazar un estudio **sin
     * ningún dato** (`QueEsUnDocumento::ecografia`); también sabe que el `-1` del escritorio y el literal
     * `none` son «sin dato». Las columnas aceptadas son las de **su mapa del dato**
     * (`SyncAppDataRequest::columnasEditables`): lo que no esté en el mapa —`puro_texto`, los resúmenes del
     * escritorio— se descarta en silencio, igual que en las ediciones.
     *
     * Lo único propio del sync es el `created` con el id temporal del lote (`anotar`), que es lo que le
     * permite al teléfono soltar su fila provisional y reintentar sin duplicar, y **el id real de la fila**
     * en la respuesta: el mapa lo busca por la clave del legado (`filaDe`), no con un `where` suelto.
     *
     * **Y un apartamiento deliberado: el estudio «sin datos» sí se crea.** La regla de la Action
     * (`QueEsUnDocumento::ecografia`) mira las columnas *con dato* —`fecha`, `fur` y `gesta` no cuentan— y
     * existe porque la web decide con ellas si ofrece la hoja impresa. Eso es impresión, no almacenamiento: el
     * escritorio crea la fila al abrir la consulta aunque no tenga datos, y el teléfono manda la creación con
     * lo que el médico cargó, así que **rechazarla le hace descartar lo escrito** (una creación perdida no se
     * reintenta: la cola local ya quedó limpia, ver el párrafo de A4c). Por eso el rechazo «sin datos» se
     * atrapa por su texto exacto —`ECO_SIN_DATOS`, para no tragarse otras validaciones— y se crea la fila con
     * la clave del legado y las columnas que sí vinieron ([self::crearEcoSinDatos]). El resto sigue igual: si
     * el estudio tiene datos, escribe la Action de la web.
     */
    private function crearEco(array $change, int $tempId, callable $rechazar): ?array
    {
        $tabla = (string) $change['table'];

        $consulta = $this->resolverConsulta($change);
        if (! $consulta) {
            $rechazar('El estudio no corresponde a una consulta de este médico.');
            return null;
        }

        // La Action y la lectura de cada estudio. La lectura se resuelve por el contenedor porque es una
        // clase sin estado que sólo sabe encontrar la fila del estudio.
        [$accion, $lectura] = match ($tabla) {
            'eco_pelvico' => [$this->guardarEcoPelvico, app(EcoGinecologico::class)],
            'eco_obstetrico' => [$this->guardarEcoObstetrico, app(EcoObstetrico::class)],
            'eco_obstetrico_tercer_2' => [$this->guardarEcoObstetricoSegundo, app(EcoObstetricoSegundo::class)],
            'eco_obstetrico_tercer' => [$this->guardarEcoObstetricoTercero, app(EcoObstetricoTercero::class)],
            // Los cuatro de la ola §10 (2026-10-19).
            'eco_doppler' => [$this->guardarEcoDoppler, app(EcoDoppler::class)],
            'ultra_mama' => [$this->guardarEcoMamario, app(EcoMamario::class)],
            'ultra_abdominal' => [$this->guardarEcoAbdominal, app(EcoAbdominal::class)],
            'ecocadiograma_fetal' => [$this->guardarEcoCardiogramaFetal, app(EcoCardiogramaFetal::class)],
        };

        $valores = $this->columnasDelCliente($change, $tabla);

        // La pantalla de la web manda el estudio **entero**; el teléfono puede mandar sólo lo que cambió.
        // Las columnas del mapa que no viajan se completan con lo que la fila ya tiene porque la Action
        // normaliza **todas** las del mapa: las ausentes quedarían en `null` —el «sin dato» del legado— y una
        // carga parcial borraría lo que el escritorio ya había guardado.
        $fila = $lectura->filaDe($consulta);
        if ($fila !== null) {
            foreach (SyncAppDataRequest::columnasEditables($tabla) as $columna) {
                if (! array_key_exists($columna, $valores)) {
                    $valores[$columna] = $fila->getAttribute($columna);
                }
            }
        }

        $creada = null;

        try {
            $accion->ejecutar($consulta, $valores, null, 'mobile');
        } catch (InvalidArgumentException $e) {
            if ($e->getMessage() !== self::ECO_SIN_DATOS) {
                // Cualquier otro rechazo (una validación que se agregue mañana) sigue su camino: el teléfono
                // lo ve en `rechazados` y no se escribe nada.
                $rechazar($e->getMessage());
                return null;
            }

            // **«Sin datos» es una regla de la hoja impresa, no del almacenamiento.** La Action es la de la
            // web y ahí un estudio que sólo trae la FUR, la gesta o la fecha no tiene informe que ofrecer, así
            // que no crea la fila. Pero el **escritorio sí la crea**: al abrir la consulta hace
            // `InsertRow` + `numhistoria` + `fecha` (`w_cargar_visualizar_ecografia.srw:221-224`) y la guarda
            // con lo que haya; y el comienzo normal de un eco obstétrico es cargar sólo la FUR y/o la gesta.
            // Rechazar la creación acá hace que el teléfono **descarte** lo escrito —es una creación, no una
            // edición—, que es justo lo que no puede pasar. Se crea la fila vacía con la clave del legado,
            // como el escritorio, y se guarda lo que sí vino (FUR, gesta, fecha).
            $creada = $this->crearEcoSinDatos($tabla, $consulta, $valores, $lectura);
        }

        // El id real de la fila, que es el `remote_id` que el teléfono guarda para seguir editándola.
        $fila = $creada ?? $lectura->filaDe($consulta);
        if ($fila === null) {
            $rechazar('El estudio no se pudo guardar.');
            return null;
        }

        $this->anotar($tabla, (int) $fila->id, $tempId, $consulta->reg_medico);

        return $this->respuestaEco($tabla, $tempId, (int) $fila->id);
    }

    /**
     * **La fila del estudio que sólo trae la FUR, la gesta o la fecha**, que es el comienzo normal de un eco
     * obstétrico: se crea igual que la crea el escritorio al abrir la consulta, con la clave del legado
     * (`filaParaEscribir`) y lo que haya viajado del mapa.
     *
     * Es el apartamiento **deliberado** de la regla de la web (ver [self::crearEco]): la Action no crea una
     * fila que no tiene informe que imprimir, pero el teléfono no puede perder una creación —lo escrito se
     * descarta y no vuelve—, y el escritorio sí la guarda. No escribe nada que no esté en el mapa, ni la
     * clave, ni `reg_medico`: eso lo pone el mapa del dato.
     *
     * Usa la misma normalización de «sin dato» que la Action (`EstudioEcografico::limpiar`: `''`, `none` y
     * el `-1` del escritorio son vacío) para que la fila quede igual que si la hubiera guardado la web.
     *
     * @param  EcoGinecologico|EcoObstetrico|EcoObstetricoTercero  $lectura
     */
    private function crearEcoSinDatos(string $tabla, Consulta $consulta, array $valores, $lectura): Model
    {
        $fila = $lectura->filaParaEscribir($consulta);

        foreach (SyncAppDataRequest::columnasEditables($tabla) as $columna) {
            if (array_key_exists($columna, $valores)) {
                $fila->setAttribute($columna, EstudioEcografico::limpiar($valores[$columna]));
            }
        }

        // `fecha` es NOT NULL y el escritorio la pone al crear la fila: si el teléfono no la mandó —o la
        // mandó vacía—, vale hoy, que es lo que propone el escritorio al abrir el estudio.
        $fila->setAttribute(
            'fecha',
            EstudioEcografico::limpiar($valores['fecha'] ?? null) ?? FechaDelConsultorio::hoyComoFecha()->toDateString(),
        );

        $fila->save();

        return $fila;
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
     * @return array<string,mixed>
     */
    private function valoresDelCliente(array $change): array
    {
        return $this->columnasDelCliente($change, 'examen_fisico_nuevo');
    }

    /**
     * Las columnas de **una tabla** que mandó el cliente en una creación, filtradas por las columnas
     * editables de esa tabla (`SyncAppDataRequest::columnasEditables`).
     *
     * Se leen de `columns` —la forma que tienen todas las creaciones con datos— y también sueltas en la
     * misma creación, porque lo que dice de qué cuelga (`consulta_id`/`consulta_temp_id`) viaja al mismo
     * nivel: así el teléfono no necesita dos formas de cuerpo para lo mismo. En los dos casos la lista
     * blanca es la que decide: abre la tabla, no sus campos. Lo que no está se descarta en silencio, igual
     * que en las ediciones (y así `numhistoria`, `nroconsulta`, `reg_medico` y `fecha` no pueden colarse).
     *
     * Para los cuatro estudios ecográficos esa lista es **el mapa del dato** del estudio y no
     * `WRITABLE_COLUMNS`: son ~180 columnas que ya están escritas en el mapa que pinta la web, y tenerlas
     * dos veces haría que una columna nueva se guardara desde la web y se descartara desde el teléfono.
     *
     * @return array<string,mixed>
     */
    private function columnasDelCliente(array $change, string $tabla): array
    {
        $columnas = (array) ($change['columns'] ?? []);

        $valores = [];
        foreach (SyncAppDataRequest::columnasEditables($tabla) as $columna) {
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
        if ($table === 'recipes_pareja') {
            // Ola §9: el reintento de una pareja ya aplicada devuelve el mismo id de la primera fila, sin
            // volver a escribir (la Action corregiría en el lugar, pero la creación ya está del otro lado).
            return $this->respuestaRecipeDeLaPareja($tempId, $recordId);
        }
        if (isset(SyncAppDataRequest::MAPAS_DE_LOS_ECOS[$table])) {
            // EC-A3: el reintento de un estudio ya creado devuelve el **mismo id** de la fila, sin volver a
            // escribir ni duplicar. Se busca por el id que anotó el rastro (es el que el teléfono guardó) y
            // no por la clave del legado, igual que en la evaluación física.
            return $this->respuestaEco($table, $tempId, $recordId);
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
     * La respuesta del récipe de la pareja es **sólo su id** (el de la primera fila): la tabla no tiene una
     * fila cabecera y el teléfono solo necesita soltar su fila pendiente —las filas reales llegan en el delta
     * de la misma respuesta—.
     */
    private function respuestaRecipeDeLaPareja(int $tempId, int $id): array
    {
        return ['table' => 'recipes_pareja', 'temp_id' => $tempId, 'id' => $id];
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

    /**
     * La respuesta de un estudio ecográfico (EC-A3) es **sólo su id**, como la de la evaluación física: la
     * fila cuelga de una consulta que el teléfono ya tiene (o recibió en este lote) y lo que necesita es el
     * id real para reemplazar el provisional de su fila local. La tabla viaja porque los cuatro estudios
     * comparten forma pero son cuatro tablas distintas.
     */
    private function respuestaEco(string $tabla, int $tempId, int $id): array
    {
        return ['table' => $tabla, 'temp_id' => $tempId, 'id' => $id];
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
