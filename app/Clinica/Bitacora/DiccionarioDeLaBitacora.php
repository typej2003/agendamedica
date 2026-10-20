<?php

namespace App\Clinica\Bitacora;

use App\Support\HoraLocal;

/**
 * El diccionario único de la **bitácora** (WEB-8.5): cómo se le llama, en lenguaje claro, a cada tabla y a cada columna que deja su
 * rastro en `sync_changes`, de qué familia es (para el filtro «tipo de dato»), cuál de sus valores se puede mostrar y cómo se llega
 * desde la fila al paciente al que pertenece.
 *
 * Es un archivo de datos con unos pocos métodos: lo que no está mapeado **no se muestra con su nombre crudo de tabla** sino con un
 * nombre genérico claro («otro dato del consultorio»), y una columna sin etiqueta se humaniza (`fe_vence` → «Fe vence»).
 *
 * **Privacidad**: la bitácora dice *qué* cambió y *quién*, no el contenido clínico. Por eso `valorVisible()` es una lista blanca
 * muy corta de datos de la agenda y de precios (monto, hora, fecha, atención de una cita) y todo lo demás —textos de la
 * consulta, indicaciones, diagnósticos, datos del paciente— sólo aparece como el *nombre* de la columna tocada.
 */
final class DiccionarioDeLaBitacora
{
    /** El nombre genérico de una tabla que el diccionario no conoce. */
    public const OTRO = 'otro dato del consultorio';

    /**
     * Las tablas que sólo existen para que el teléfono se entere de una baja (alias de `recipe_grupo*`): mostrarlas duplicaría
     * cada baja de un tratamiento (trampa 57 de `TRAMPAS.md`).
     */
    public const ALIAS_DEL_TELEFONO = ['tratamientos', 'tratamientos_detalle'];

    /**
     * Los tipos de dato: `clave => [familia, etiqueta singular, género (m|f), tablas]`. Dos tablas pueden ser el mismo tipo para
     * quien lee (la cabecera de un récipe y sus medicamentos son «un récipe»).
     */
    private const TIPOS = [
        'cita' => ['Agenda', 'cita', 'f', ['cola']],
        'horario' => ['Agenda', 'horario del consultorio', 'm', ['office_schedules']],
        'motivo_cita' => ['Agenda', 'motivo de cita', 'm', ['motivo_cita']],

        'paciente' => ['Pacientes', 'paciente', 'm', ['pacientes']],
        'historia' => ['Pacientes', 'historia clínica', 'f', ['historias']],
        'representante' => ['Pacientes', 'representante', 'm', ['representante']],
        'antecedente' => ['Pacientes', 'antecedente', 'm', ['antece_paciente']],

        'consulta' => ['Consulta', 'consulta', 'f', ['consultas']],
        'motivo' => ['Consulta', 'motivo de consulta', 'm', ['motivo_consulta_paciente']],
        'evaluacion' => ['Consulta', 'evaluación física', 'f', ['examen_fisico_nuevo']],
        'diagnostico' => ['Consulta', 'diagnóstico', 'm', ['diagnostico_paciente']],
        'imagen' => ['Consulta', 'imagen de la consulta', 'f', ['imagen_consulta']],

        'recipe' => ['Récipes', 'récipe', 'm', ['recipes', 'recipe_detalle']],
        'recipe_pareja' => ['Récipes', 'récipe de la pareja', 'm', ['recipes_pareja']],

        'laboratorio' => ['Solicitudes y documentos', 'orden de laboratorio', 'f', ['examen_paciente', 'examen_obs']],
        'estudios' => ['Solicitudes y documentos', 'solicitud de estudios', 'f', ['radiologia_paciente', 'radiologia_obs']],
        'constancia' => ['Solicitudes y documentos', 'constancia', 'f', ['constancia_obs']],
        'reposo' => ['Solicitudes y documentos', 'reposo', 'm', ['reposo_paciente']],
        'referencia' => ['Solicitudes y documentos', 'referencia', 'f', ['referencia']],
        'informe' => ['Solicitudes y documentos', 'informe médico', 'm', ['informe']],
        'hospitalizacion' => ['Solicitudes y documentos', 'hospitalización', 'f', ['hospitalizacion']],
        'dieta' => ['Solicitudes y documentos', 'dieta', 'f', ['dieta_paciente']],

        'medicamento' => ['Catálogos', 'medicamento del vademécum', 'm', ['vademecum']],
        'tratamiento' => ['Catálogos', 'tratamiento', 'm', ['recipe_grupo', 'recipe_grupo_detalle']],
        'diagnostico_catalogo' => ['Catálogos', 'diagnóstico del catálogo', 'm', ['diagnosticos']],
        'motivo_catalogo' => ['Catálogos', 'motivo de consulta del catálogo', 'm', ['motivos_consulta']],
        'examen_catalogo' => ['Catálogos', 'examen del catálogo', 'm', ['examenes']],
        'tipo_examen' => ['Catálogos', 'tipo de examen', 'm', ['tipos_examenes']],
        'doctor' => ['Catálogos', 'médico referido', 'm', ['doctores']],
        'plantilla_texto' => ['Catálogos', 'plantilla de texto', 'f', ['formato_print']],

        'configuracion' => ['Configuración', 'configuración del médico', 'f', ['evolucion']],
    ];

    /**
     * Cómo se llega de una fila de rastro al paciente. `historia`: la tabla trae el número de historia en esa columna (más el
     * `reg_medico`); `paciente`: el `id` de la fila es el del paciente; `historias`: la fila trae `paciente_id`; `cola` es una
     * historia más el paciente "sin historia" de la cita.
     */
    private const PACIENTE_POR = [
        'cola' => ['cola', 'numhistoria'],
        'consultas' => ['historia', 'numhistoria'],
        'recipes' => ['historia', 'nrohistoria'],
        'recipe_detalle' => ['historia', 'nrohistoria'],
        'recipes_pareja' => ['historia', 'nrohistoria'],
        'diagnostico_paciente' => ['historia', 'nrohistoria'],
        'examen_paciente' => ['historia', 'nrohistoria'],
        'examen_obs' => ['historia', 'numhistoria'],
        'radiologia_paciente' => ['historia', 'nrohistoria'],
        'radiologia_obs' => ['historia', 'numhistoria'],
        'reposo_paciente' => ['historia', 'nrohistoria'],
        'referencia' => ['historia', 'nrohistoria'],
        'informe' => ['historia', 'nrohistoria'],
        'constancia_obs' => ['historia', 'numhistoria'],
        'hospitalizacion' => ['historia', 'nrohistoria'],
        'dieta_paciente' => ['historia', 'histroria'],
        'motivo_consulta_paciente' => ['historia', 'nrohistoria'],
        'antece_paciente' => ['historia', 'numhistoria'],
        'imagen_consulta' => ['historia', 'nrohistoria'],
        'representante' => ['historia', 'numhistoria'],
        'examen_fisico_nuevo' => ['historia', 'historia'],
        'historias' => ['historias', 'paciente_id'],
        'pacientes' => ['paciente', 'id'],
    ];

    /** Las etiquetas de las columnas que se repiten entre tablas. */
    private const COLUMNAS = [
        'estado' => 'Estado', 'atendido' => 'Atención', 'monto' => 'Monto', 'monto_pagado' => 'Monto pagado',
        'fecha' => 'Fecha', 'hora_ini' => 'Hora', 'hora_fin' => 'Hora de fin', 'numorden' => 'Orden en la cola',
        'turno' => 'Turno', 'motivo' => 'Motivo', 'tipo' => 'Motivo', 'medical_center_id' => 'Sede', 'office_id' => 'Sede',
        'numhistoria' => 'Historia', 'cantidad' => 'Cantidad', 'indicaciones' => 'Indicaciones', 'descripcion' => 'Descripción',
        'orden' => 'Orden', 'codemedicina' => 'Medicamento', 'enfermedadactual' => 'Enfermedad actual', 'evolucion' => 'Evolución',
        'observaciones' => 'Observaciones', 'observacion' => 'Observación', 'observacion01' => 'Observación adicional',
        'titulo' => 'Título', 'peso' => 'Peso', 'talla' => 'Talla', 'fc' => 'Frecuencia cardíaca', 'pp' => 'Perímetro',
        'tension' => 'Tensión', 'general' => 'Examen general', 'neurologico' => 'Examen neurológico',
        'detalle_diagnostco' => 'Detalle del diagnóstico', 'detalles' => 'Detalle', 'tipo2' => 'Alerta',
        'referencia' => 'Motivo de la referencia', 'fdesde' => 'Inicio', 'numdias' => 'Días de reposo',
        'obser_reposo' => 'Justificación', 'fe_cha' => 'Fecha', 'fe_emision' => 'Fecha de emisión', 'fe_vence' => 'Vigencia',
        'nota' => 'Nota', 'para' => 'Para', 'hospital' => 'Hospital', 'diagprev' => 'Diagnóstico', 'dieta' => 'Dieta',
        'resultado' => 'Resultado', 'vinculante' => 'Vinculante', 'imagen' => 'Archivo', 'nombre' => 'Nombre',
        'cedula' => 'Cédula', 'nombres' => 'Nombres', 'apellidos' => 'Apellidos', 'telefono' => 'Teléfono', 'email' => 'Correo',
        'direccion' => 'Dirección', 'fnacimiento' => 'Fecha de nacimiento', 'sexo' => 'Sexo', 'codesegemp' => 'Seguro',
        'codeparentesco' => 'Parentesco', 'nombrecomercial' => 'Nombre comercial', 'nombregenerico' => 'Nombre genérico',
        'presentacion' => 'Presentación', 'uso' => 'Uso', 'precio' => 'Precio', 'admite_varias_citas_pendientes' => 'Varias citas pendientes',
        'dia_semana' => 'Día', 'hora_inicio' => 'Hora de inicio', 'cupo' => 'Cupo', 'plantilla_cita' => 'Mensaje de recordatorio',
        'plantilla_cumple' => 'Saludo de cumpleaños', 'logo' => 'Logo', 'especial' => 'Especialidad', 'ciudad' => 'Ciudad',
        'clinica' => 'Clínica', 'enviar_cumpleanos' => 'Saludo automático', 'procedencia' => 'Procedencia',
    ];

    /**
     * Los únicos valores que la bitácora muestra, por `tabla.columna`: datos de la agenda y de precios, nada clínico. El valor es el
     * que quedó guardado (no se guarda el anterior, así que no hay «de X a Y»).
     */
    private const VALORES_VISIBLES = [
        'cola.monto', 'cola.monto_pagado', 'cola.fecha', 'cola.hora_ini', 'cola.hora_fin', 'cola.atendido', 'motivo_cita.precio',
    ];

    // ------------------------------------------------------------------------------------------------ Tipos

    /** @return array{0:string,1:string,2:string,3:array<int,string>}|null */
    public static function tipoDeTabla(string $tabla): ?array
    {
        foreach (self::TIPOS as $clave => $tipo) {
            if (in_array($tabla, $tipo[3], true)) {
                return [$clave, ...array_slice($tipo, 0, 3)];
            }
        }

        return null;
    }

    /** «una cita», «un récipe», «otro dato del consultorio»: el sustantivo con su artículo. */
    public static function conArticulo(string $tabla): string
    {
        $tipo = self::tipoDeTabla($tabla);

        if ($tipo === null) {
            return 'un ' . self::OTRO;
        }

        return ($tipo[3] === 'f' ? 'una ' : 'un ') . $tipo[2];
    }

    /** El nombre del tipo de una tabla, en singular y sin artículo («cita», «récipe»). */
    public static function etiquetaDeTabla(string $tabla): string
    {
        return self::tipoDeTabla($tabla)[2] ?? self::OTRO;
    }

    /** @return array<int,string> las tablas de un tipo; vacío si la clave no existe */
    public static function tablasDelTipo(string $clave): array
    {
        return self::TIPOS[$clave][3] ?? [];
    }

    public static function existeElTipo(string $clave): bool
    {
        return isset(self::TIPOS[$clave]);
    }

    /**
     * Las opciones del filtro «tipo de dato», agrupadas por familia.
     *
     * @return array<string,array<string,string>> `familia => [clave => etiqueta]`
     */
    public static function opcionesDeTipo(): array
    {
        $opciones = [];

        foreach (self::TIPOS as $clave => [$familia, $etiqueta]) {
            $opciones[$familia][$clave] = ucfirst($etiqueta);
        }

        return $opciones;
    }

    // ---------------------------------------------------------------------------------------------- Columnas

    public static function etiquetaDeColumna(string $tabla, string $columna): string
    {
        if ($tabla === 'cola' && $columna === 'tipo') {
            return 'Motivo';
        }

        if ($tabla === 'antece_paciente' && $columna === 'tipo') {
            return 'Tipo de antecedente';
        }

        return self::COLUMNAS[$columna] ?? ucfirst(str_replace('_', ' ', $columna));
    }

    public static function esVisibleElValor(string $tabla, string $columna): bool
    {
        return in_array($tabla . '.' . $columna, self::VALORES_VISIBLES, true);
    }

    /**
     * El valor de una columna visible ya redactado («Monto 25,00», «Atendida»), o `null` si no se muestra.
     */
    public static function valorVisible(string $tabla, string $columna, ?string $valor): ?string
    {
        if (! self::esVisibleElValor($tabla, $columna) || $valor === null || trim($valor) === '') {
            return null;
        }

        $valor = trim($valor);

        if ($tabla === 'cola' && $columna === 'atendido') {
            return $valor === '1' ? 'atendida' : 'sin atender';
        }

        if ($columna === 'fecha' && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $valor, $m) === 1) {
            return $m[3] . '/' . $m[2] . '/' . $m[1];
        }

        if (in_array($columna, ['hora_ini', 'hora_fin'], true) && preg_match('/^(\d{2}:\d{2})/', $valor, $m) === 1) {
            return HoraLocal::hora12($m[1]);
        }

        if (is_numeric($valor) && in_array($columna, ['monto', 'monto_pagado', 'precio'], true)) {
            return number_format((float) $valor, 2, ',', '.');
        }

        return null;
    }

    /** @return array<int,string> los nombres de columna de los que se puede traer el valor, por tabla */
    public static function columnasVisiblesDe(string $tabla): array
    {
        $columnas = [];

        foreach (self::VALORES_VISIBLES as $clave) {
            [$t, $c] = explode('.', $clave, 2);

            if ($t === $tabla) {
                $columnas[] = $c;
            }
        }

        return $columnas;
    }

    /** @return array<int,string> las tablas con algún valor visible */
    public static function tablasConValorVisible(): array
    {
        return array_values(array_unique(array_map(fn ($clave) => explode('.', $clave, 2)[0], self::VALORES_VISIBLES)));
    }

    // ----------------------------------------------------------------------------------------------- Paciente

    /**
     * Cómo se llega al paciente desde una tabla: `[tipo, columna]` (ver `PACIENTE_POR`), o `null` si no se puede.
     *
     * @return array{0:string,1:string}|null
     */
    public static function pacientePor(string $tabla): ?array
    {
        return self::PACIENTE_POR[$tabla] ?? null;
    }

    /** @return array<int,string> las tablas que se pueden relacionar con un paciente */
    public static function tablasConPaciente(): array
    {
        return array_keys(self::PACIENTE_POR);
    }
}
