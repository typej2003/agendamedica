<?php

namespace App\Http\Requests;

use App\Models\Cola;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación del payload de `/app/sync-app-data` y **única fuente de verdad de qué puede tocar
 * el cliente**: las listas blancas de tablas y columnas viven acá, no repartidas en el controlador.
 *
 * ⚠️ **Por qué la validación es de forma y no de columnas**: si un `change` con una columna
 * desconocida hiciera fallar la petición entera con 422, el cliente se quedaría con la cola
 * trabada — reintentaría el mismo lote para siempre y, peor, **no podría ni bajar datos**, porque
 * pull y push viajan en la misma petición. Un teléfono con una versión vieja o nueva del app
 * dejaría de sincronizar del todo.
 *
 * Por eso: acá se valida que el payload tenga forma válida (tipos, enums, campos obligatorios) y
 * el controlador **descarta** los cambios que no pasan la lista blanca de columnas, siguiendo con
 * el resto. Las constantes de abajo son las que consulta para filtrar.
 */
class SyncAppDataRequest extends FormRequest
{
    /**
     * Columnas editables por `changes`, por tabla.
     *
     * `cola.fecha` es editable desde el Paso 19.C: **reagendar es editar la misma cita**, no
     * cancelar y crear otra (así conserva lo cobrado y su historial de cambios). Cambiar de
     * paciente (`numhistoria`) sigue sin estar permitido.
     */
    public const WRITABLE_COLUMNS = [
        'cola' => [
            'fecha', 'numorden', 'atendido', 'estado', 'turno', 'motivo', 'monto', 'monto_pagado',
            'hora_ini', 'hora_fin', 'tiempo', 'tipo', 'sms_text', 'medical_center_id',
        ],
        'pacientes' => [
            'nac', 'cedula', 'apellidos', 'nombres', 'sexo', 'fnacimiento', 'lnacimiento',
            'codeestado', 'direccion', 'telefono', 'fingreso', 'escolaridad', 'ocupacion',
            'profesion', 'email', 'dependencia', 'sms',
        ],
        // A1 de PLAN-MOVIL.md: lo que el teléfono puede **editar mientras se atiende**, y lo único —
        // entrar en `TABLAS_EDITABLES` no habilita el resto de la tabla. Son las columnas clínicas que
        // ya escribe la web por sus Actions: enfermedad actual y evolución
        // (`GuardarEnfermedadActual`, `GuardarEvolucionDeLaConsulta`), las medidas y los hallazgos de
        // la evaluación física (`GuardarEvaluacionFisica`) y el texto de tratamiento, que en el legado
        // es `consultas.laringe` (WEB-5.7). No están los resúmenes narrativos del examen (`total_f`,
        // `total_g`: los arma el escritorio, trampa de PLAN-MOVIL.md §7.6) ni columnas de otra
        // especialidad.
        'consultas' => [
            'enfermedadactual', 'evolucion', 'laringe',
            'peso', 'talla', 'fc', 'pp', 'circcefalica', 'circtoraxica', 'circabdominal',
        ],
        'examen_fisico_nuevo' => [
            'tension', 'general', 'cardiopulmonar', 'tiroides', 'mamas', 'mama_derecha',
            'abdomen', 'extremidades', 'neurologico', 'genitales_externos', 'especulos',
            'tacto_vaginal', 'tacto_rectal',
        ],
        // A4b de PLAN-MOVIL.md: el detalle y la casilla de **Alerta** (`tipo2`) del antecedente, que es
        // lo único que la sección deja editar de una fila que ya existe (el DataWindow del escritorio
        // sólo actualiza esas dos columnas; ver `GuardarAntecedentes`). `descripcion` y `tipo` están
        // porque son la copia del catálogo que la fila guarda y el teléfono puede reenviarla al corregir.
        // **`numhistoria` y `codeantecedente` no están**: cambiar de paciente o de antecedente no es una
        // edición, es otra fila.
        'antece_paciente' => ['detalles', 'descripcion', 'tipo', 'tipo2'],
        // El detalle de un diagnóstico: `detalle_diagnostco` es **el texto propio del diagnóstico** —la corrección
        // que el médico escribe sobre la descripción del catálogo— y `orden`, el lugar que ocupa en la
        // lista; las dos se pueden corregir sobre una fila que ya está cargada. `codediagnostico`,
        // `nrohistoria` y `nroconsulta` **no** están: cambiar de diagnóstico, de consulta o de paciente
        // no es una edición de la fila, es otra fila (se quita una y se agrega la otra).
        'diagnostico_paciente' => ['detalle_diagnostco', 'orden'],
    ];

    /**
     * Columnas aceptadas al *crear*. Lista aparte porque hay campos que solo tienen sentido al
     * nacer la cita (`numhistoria`): editarlo después sería "cambiar de paciente", una acción con
     * reglas propias todavía sin definir.
     *
     * `reg_medico` no se acepta nunca del cliente — lo resuelve el servidor desde el médico
     * autenticado, que es lo único que marca el tenant. `medico` (la `clave` de `evolucion`, Paso
     * 22.B) sí, desde el Paso 22.C: en un tenant con más de un médico, quien agenda elige para
     * cuál es la cita. Si no lo manda (el caso común, un solo médico en la instancia), el servidor
     * resuelve la del médico autenticado (`Medico::claveDeEvolucion`).
     */
    public const CREATABLE_COLUMNS = [
        'cola' => [
            'fecha', 'hora_ini', 'hora_fin', 'numhistoria', 'medical_center_id', 'numorden',
            'atendido', 'estado', 'turno', 'motivo', 'monto', 'monto_pagado', 'tiempo', 'tipo',
            'sms_text', 'medico',
        ],
        // Un paciente creado desde el app **nace sin `numhistoria`**: el alta rápida (la secretaria
        // por teléfono) no la pide. El vínculo con el médico queda en el pivote `medico_pacientes`,
        // que admite `numhistoria` nulo, y la cita lo referencia por `paciente_sinhistoria_id`,
        // columna que el esquema legado ya trae para este caso. La historia se llena después con
        // una creación de `historias` (Paso 18.B), con el correlativo que asigna el servidor.
        'pacientes' => [
            'nac', 'cedula', 'apellidos', 'nombres', 'sexo', 'fnacimiento', 'lnacimiento',
            'codeestado', 'direccion', 'telefono', 'fingreso', 'escolaridad', 'ocupacion',
            'profesion', 'email', 'dependencia', 'sms',
        ],
    ];

    /**
     * Sin estas columnas la fila no se crea. En `cola` son las `NOT NULL` del esquema (sin ellas
     * el INSERT explota); en `pacientes` son las que hacen identificable a la persona: la cédula
     * es además la clave por la que el legado reconoce al mismo paciente entre médicos, así que
     * sin ella se crearían fichas duplicadas.
     */
    public const REQUIRED_COLUMNS = [
        'cola' => ['fecha', 'hora_ini'],
        'pacientes' => ['cedula', 'nombres', 'apellidos'],
    ];

    /** Columnas con dominio cerrado. Ver la convención de estados en `App\Models\Cola`. */
    public const ALLOWED_VALUES = [
        'cola' => [
            'estado' => Cola::ESTADOS,
            'atendido' => [0, 1],
        ],
    ];

    /** Columnas que tienen que venir como fecha `YYYY-MM-DD`: una fecha mal formada se descarta. */
    public const DATE_COLUMNS = [
        'cola' => ['fecha'],
    ];

    /**
     * Tablas que el app puede **editar** (`operation = updated`) por el camino genérico.
     *
     * Son las dos de siempre —la cita y la ficha del paciente— más las clínicas que el médico toca
     * mientras atiende (A1 de PLAN-MOVIL.md). Cuáles columnas de cada una, lo dice `WRITABLE_COLUMNS`:
     * esta lista abre la tabla, no sus campos.
     *
     * `diagnostico_paciente` y `antece_paciente` figuran porque **toda tabla de `TABLAS_BORRABLES`
     * tiene que estar acá**: el borrado de una fila y su edición pasan por el mismo camino, y una baja
     * fuera de esta lista no se aplicaría nunca.
     */
    public const TABLAS_EDITABLES = [
        'cola', 'pacientes', 'consultas', 'examen_fisico_nuevo', 'diagnostico_paciente', 'antece_paciente',
    ];

    /**
     * Tablas que el app puede **borrar** (`operation = deleted`).
     *
     * Un diagnóstico y un antecedente se agregan y se quitan desde la consulta, así que su baja entra
     * desde el teléfono (con el camino de A1; su alta es de A4). Una **consulta** y un **examen físico**
     * no: son la historia clínica y su evaluación —la consulta lleva el número que asigna el servidor y
     * cuelga de ella todo lo demás—, y borrarlos desde el app dejaría filas huérfanas que el escritorio
     * no sabe leer. Esa decisión se toma en la web.
     */
    public const TABLAS_BORRABLES = [
        'cola', 'pacientes', 'diagnostico_paciente', 'antece_paciente',
    ];

    /**
     * Nombre viejo de `TABLAS_BORRABLES`, cuando la lista era además el gate de las **ediciones**.
     *
     * Se conserva porque hay código y tests que la consultan por este nombre; lo que hace hoy el
     * controlador es `TABLAS_EDITABLES` para `updated` y `TABLAS_BORRABLES` para `deleted`.
     *
     * @deprecated usar `TABLAS_BORRABLES`.
     */
    public const DELETABLE_TABLES = self::TABLAS_BORRABLES;

    /**
     * Creaciones clínicas del Paso 18.B (Atender → consulta → récipe). No van por
     * `CREATABLE_COLUMNS`: el número de cada una (historia, consulta, récipe) **lo asigna el
     * servidor** con el correlativo vigente, y el cliente solo manda a qué cuelga. Ver
     * `SyncAppDataController::crearHistoria` y siguientes.
     */
    public const CLINICAL_TABLES = [
        'historias', 'consultas', 'recipes',
        // Paso 18.B2: agregar un motivo (del catálogo) a una consulta.
        'motivo_consulta_paciente',
        // A3 de PLAN-MOVIL.md: la **evaluación física** de la consulta. Es la única creación clínica que
        // **sí** trae columnas: no tiene número que asignar, cuelga de la consulta y el servidor resuelve
        // la clave del legado `(historia, consulta)` —donde `consulta` es el **número** de la consulta, no
        // su id— con la Action de la web (`GuardarEvaluacionFisica`). Sólo se aceptan las columnas de
        // `WRITABLE_COLUMNS['examen_fisico_nuevo']`.
        'examen_fisico_nuevo',
        // A4a de PLAN-MOVIL.md: el **alta de un diagnóstico de la consulta** (`diagnostico_paciente`). Es
        // una creación clínica y no una fila que el teléfono numere: la fila cuelga de la consulta por sus
        // números (`nrohistoria` + `nroconsulta`), y el `codediagnostico` que manda el cliente tiene que
        // estar en el catálogo `diagnosticos` del médico. Su baja ya viaja por `TABLAS_BORRABLES` desde A1.
        'diagnostico_paciente',
        // A4b de PLAN-MOVIL.md: el **alta de un antecedente del paciente** (`antece_paciente`). A
        // diferencia de un diagnóstico, la fila **no cuelga de la consulta**: es del paciente y se llavea
        // por `numhistoria` + `codeantecedente`, así que la creación resuelve la historia (real o del
        // mismo lote) y valida el código contra el catálogo `antecedentes` del médico. Su baja ya viaja
        // por `TABLAS_BORRABLES` desde A1.
        'antece_paciente',
    ];

    /**
     * Tablas que pueden aparecer en `changes`, con cualquier operación. `motivo_consulta_paciente`
     * además acepta `deleted` (quitar un motivo de la consulta; el catálogo nunca se borra desde el app).
     *
     * `examen_fisico_nuevo`, `diagnostico_paciente` y `antece_paciente` están desde A1 (PLAN-MOVIL.md):
     * sin ellas en esta lista, `changes.*.table` falla con 422 y **el lote entero se rechaza** —incluido
     * el pull—, que es justo lo que el comentario de la clase evita. La autorización de qué fila y qué
     * columna sí se puede tocar sigue estando en `TABLAS_EDITABLES` / `WRITABLE_COLUMNS`.
     */
    public const SYNC_TABLES = [
        'cola', 'pacientes', 'historias', 'consultas', 'recipes', 'motivo_consulta_paciente',
        'examen_fisico_nuevo', 'diagnostico_paciente', 'antece_paciente',
    ];

    /**
     * Columna de ordenamiento por tabla, y el campo que delimita el grupo dentro del cual se
     * ordena. Reordenar es relativo: mover una cita "al segundo lugar" solo tiene sentido
     * dentro de un día concreto.
     */
    public const ORDER_COLUMN = ['cola' => 'numorden'];

    public const ORDER_SCOPE_COLUMN = ['cola' => 'fecha'];

    public function authorize(): bool
    {
        // La autorización real es por tenant y se resuelve fila por fila en el controlador
        // (cada registro tiene que pertenecer al médico autenticado). Acá no hay nada que decidir.
        return true;
    }

    public function rules(): array
    {
        return [
            'since' => ['sometimes', 'nullable', 'date'],

            'changes' => ['sometimes', 'array'],
            'changes.*.table' => ['required', Rule::in(self::SYNC_TABLES)],
            'changes.*.operation' => ['required', Rule::in(['created', 'updated', 'deleted', 'reorder'])],

            // `record_id` identifica una fila que ya existe; `temp_id`, una que el cliente creó
            // offline. Cada operación trae el suyo y nunca los dos.
            'changes.*.record_id' => ['required_if:changes.*.operation,updated,deleted,reorder', 'integer'],
            'changes.*.temp_id' => ['required_if:changes.*.operation,created', 'integer'],

            'changes.*.column' => ['required_if:changes.*.operation,updated', 'string', 'max:64'],
            // Opcional: las creaciones clínicas no traen columnas propias. Una cita o un paciente
            // sin ellas los rechaza el controlador por `REQUIRED_COLUMNS`, sin trabar el lote.
            'changes.*.columns' => ['sometimes', 'array'],

            // Cita de un paciente creado en este mismo lote, que todavía no tiene id real ni
            // `numhistoria`: viaja la referencia al id temporal del paciente y el servidor la
            // resuelve. Sin esto, crear paciente y cita sin señal sería imposible — el teléfono
            // no puede saber con qué id quedó el paciente hasta que sincroniza.
            'changes.*.paciente_temp_id' => ['sometimes', 'nullable', 'integer'],

            // Paso 18.B: cada creación clínica cuelga de la anterior, que puede haberse creado en
            // este mismo lote (id temporal) o ya existir en el servidor (id real / número). La
            // `fecha` y los `items` se validan en el controlador, que rechaza **esa** creación: acá
            // un 422 trabaría el lote entero (ver el comentario de la clase).
            'changes.*.paciente_id' => ['sometimes', 'nullable', 'integer'],
            'changes.*.historia_temp_id' => ['sometimes', 'nullable', 'integer'],
            'changes.*.numhistoria' => ['sometimes', 'nullable', 'integer'],
            'changes.*.consulta_temp_id' => ['sometimes', 'nullable', 'integer'],
            'changes.*.consulta_id' => ['sometimes', 'nullable', 'integer'],
            // Paso 18.B2: un motivo de consulta cuelga de una consulta (arriba) y de un motivo que ya
            // está en el catálogo del médico (`codemotivo`).
            'changes.*.codemotivo' => ['sometimes', 'nullable', 'string', 'max:20'],
            'changes.*.fecha' => ['sometimes', 'nullable', 'string'],
            'changes.*.items' => ['sometimes', 'array'],

            // `reorder`: mover una fila dentro de su grupo. En vez de mandar un `updated` por
            // cada fila desplazada, se manda el movimiento y el servidor corre el resto con un
            // solo UPDATE ... WHERE BETWEEN. Ver `applyReorder` para el porqué.
            'changes.*.from' => ['required_if:changes.*.operation,reorder', 'integer'],
            'changes.*.to' => ['required_if:changes.*.operation,reorder', 'integer'],
            'changes.*.scope_date' => ['required_if:changes.*.operation,reorder', 'date'],

            'changes.*.occurred_at' => ['sometimes', 'nullable', 'date'],

            // Volcado paginado (primera sincronización de un médico grande): `inicio` arranca y después
            // viaja el cursor que devolvió el servidor, tal cual. `volcado_limite` es cuántas filas por
            // tramo pide el cliente (el servidor lo acota).
            'volcado' => ['sometimes', 'nullable', 'string', 'max:64'],
            'volcado_limite' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:5000'],

            // Pedidos puntuales del app (2026-10-14), para lo que la ventana del volcado deja afuera:
            // el historial de **un** paciente (por su número de historia) y las citas de un rango de días.
            // Las fechas y el tope del rango se validan en el controlador, que responde 400.
            'volcado_paciente' => ['sometimes', 'nullable', 'integer'],
            'volcado_agenda' => ['sometimes', 'nullable', 'string', 'max:30'],
        ];
    }

    public function messages(): array
    {
        return [
            'changes.*.table.in' => 'Esa tabla no acepta cambios desde el app.',
            'changes.*.operation.in' => 'Operación de sincronización desconocida.',
        ];
    }

    /** Los cambios ya validados de forma. El filtrado por columna lo hace el controlador. */
    public function cambios(): array
    {
        return $this->input('changes', []);
    }
}
