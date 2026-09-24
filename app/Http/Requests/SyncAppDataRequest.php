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
    ];

    /**
     * Columnas aceptadas al *crear*. Lista aparte porque hay campos que solo tienen sentido al
     * nacer la cita (`numhistoria`): editarlo después sería "cambiar de paciente", una acción con
     * reglas propias todavía sin definir.
     *
     * `reg_medico` y `medico` no se aceptan nunca del cliente — los resuelve el servidor desde el
     * médico autenticado, que es lo único que marca el tenant.
     */
    public const CREATABLE_COLUMNS = [
        'cola' => [
            'fecha', 'hora_ini', 'hora_fin', 'numhistoria', 'medical_center_id', 'numorden',
            'atendido', 'estado', 'turno', 'motivo', 'monto', 'monto_pagado', 'tiempo', 'tipo',
            'sms_text',
        ],
        // Un paciente creado desde el app **nace sin `numhistoria`**: ese número lo asigna el
        // sistema de escritorio y no se puede mintear acá sin arriesgar un choque con él (la
        // columna es única global en `historias`). El vínculo con el médico queda en el pivote
        // `medico_pacientes`, que admite `numhistoria` nulo, y la cita lo referencia por
        // `paciente_sinhistoria_id` — columna que el esquema legado ya trae para este caso.
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

    public const DELETABLE_TABLES = ['cola', 'pacientes'];

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
            'changes.*.table' => ['required', Rule::in(self::DELETABLE_TABLES)],
            'changes.*.operation' => ['required', Rule::in(['created', 'updated', 'deleted', 'reorder'])],

            // `record_id` identifica una fila que ya existe; `temp_id`, una que el cliente creó
            // offline. Cada operación trae el suyo y nunca los dos.
            'changes.*.record_id' => ['required_if:changes.*.operation,updated,deleted,reorder', 'integer'],
            'changes.*.temp_id' => ['required_if:changes.*.operation,created', 'integer'],

            'changes.*.column' => ['required_if:changes.*.operation,updated', 'string', 'max:64'],
            'changes.*.columns' => ['required_if:changes.*.operation,created', 'array'],

            // Cita de un paciente creado en este mismo lote, que todavía no tiene id real ni
            // `numhistoria`: viaja la referencia al id temporal del paciente y el servidor la
            // resuelve. Sin esto, crear paciente y cita sin señal sería imposible — el teléfono
            // no puede saber con qué id quedó el paciente hasta que sincroniza.
            'changes.*.paciente_temp_id' => ['sometimes', 'nullable', 'integer'],

            // `reorder`: mover una fila dentro de su grupo. En vez de mandar un `updated` por
            // cada fila desplazada, se manda el movimiento y el servidor corre el resto con un
            // solo UPDATE ... WHERE BETWEEN. Ver `applyReorder` para el porqué.
            'changes.*.from' => ['required_if:changes.*.operation,reorder', 'integer'],
            'changes.*.to' => ['required_if:changes.*.operation,reorder', 'integer'],
            'changes.*.scope_date' => ['required_if:changes.*.operation,reorder', 'date'],

            'changes.*.occurred_at' => ['sometimes', 'nullable', 'date'],
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
