<?php

/*
|--------------------------------------------------------------------------
| Manifiesto de la especialidad PEDIATRÍA (AppDDR)
|--------------------------------------------------------------------------
|
| Ver PLAN-WEB.md, regla R2. Es la SEGUNDA especialidad (WEB-7) y la que tiene más médicos activos:
| su escritorio es `Ddrpedia` / PediaReport, con su propia copia de la base (140 tablas).
|
| Los datos salen de fuentes verificadas, no de suposiciones:
|  - tablas que el API no tenía y columnas nuevas: `Docs/MICONSULTAGI-legado/diff-columnas-especialidades.md`
|    (generado con `bridge/tools/diff-columnas-especialidades.php` sobre `esquemas-asa/pediatria.sql`,
|    unload real de una instalación, SQL Anywhere 8.0.1.2600);
|  - tablas propias vs. compartidas: las que también aparecen en el esquema de otra especialidad
|    (`diff-esquemas-especialidades.md`), para que el validador de manifiestos no las vea como propias
|    de dos;
|  - ventanas: `Docs/MICONSULTAGI-legado/fuentes-pediatria/` (309 ventanas extraídas del .pbl de pediatría).
|
| ⚠️ **El `codeespecial` todavía no está**: el de ginecología salió del catálogo `especial` del dump
| (`020`), y de la instalación de pediatría solo tenemos el esquema, no sus datos. Se agrega cuando
| llegue el DSN o un dump de la base real (WEB-0.2c). Mientras tanto el reconocimiento es por nombre.
|
*/

return [

    'nombre' => 'Pediatría',

    // Código corto de AppDDR (el de `specialties.codigo`).
    'codigo' => 'PED',

    // Fila del catálogo `specialties` (ya existe con este slug: la siembra el `SpecialtySeeder`).
    'specialty_slug' => 'pediatria',

    // Cómo llama el legado a esta especialidad. `codigos_legado` queda vacío a propósito (ver arriba).
    'codigos_legado' => [],
    'nombres_legado' => ['PEDIATRIA', 'PEDIATRA', 'PEDIA', 'PEDIATRIA Y PUERICULTURA'],

    /*
    | Módulos clínicos (slugs del catálogo `modulos_clinicos`). Los nueve primeros son el núcleo
    | compartido; los dos últimos son propios de esta versión y se siembran **ocultos** hasta que sus
    | pantallas existan (WEB-7.4.3 y WEB-7.4.4).
    */
    'modulos' => [
        // Núcleo
        'agenda', 'pacientes', 'historia', 'consulta', 'recetas', 'documentos',
        'catalogos', 'configuracion', 'reportes',
        // Propios de pediatría
        'crecimiento', 'vacunas',
    ],

    /*
    | Tablas del módulo: las usa SOLO esta especialidad (30 de las 56 que el API no tenía). Las que
    | comparte con otra versión del escritorio van en `compartidas`.
    */
    'propias' => [
        // Crecimiento: una tabla por gráfica (una fila por fecha y medida) y los percentiles de
        // referencia (bandas por edad en días). Ver la migración y `../../Docs/Web/relevamiento-pediatria.md`.
        'grafica_peso', 'grafica_talla', 'grafica_peso_talla', 'grafica_imc',
        'grafica_circunferencia_c', 'grafica_brazo', 'grafica_presio_s', 'grafica_presio_d',
        'percentil_nino', 'percentil_nina', 'percentil_pa_sisto_m', 'percentil_pa_diast_m',
        'percentil_pulso_a_m',
        // Control de niño sano: calendario de vacunas (Vacuven), su inventario y las alertas/tareas.
        'vacunas', 'vacuna_paciente', 'vacunas_dosis', 'tipo_vacuna', 'vacunas_aplicadas',
        'vacunas_compra', 'vacunas_compradas', 'vacunas_salidas',
        'alertas', 'alertas_paciente', 'tareas', 'tareas_paciente', 'dietas', 'cuestionario',
        // El paciente pediátrico y su familia (el chamo, con su representante y sus banderas).
        'paciente_chamo', 'familia_bebe',
        // Otras pantallas que trae la versión pediátrica (gastro pediátrica / espirometría).
        'espiro',
    ],

    /*
    | Tablas que usan varias especialidades: el escritorio de la otra versión también las escribe, así
    | que declararlas "propias" acá haría fallar el validador (`RegistroEspecialidades`). La lista sale
    | de cruzar los esquemas reales: al lado, dónde más aparece.
    */
    'compartidas' => [
        'grafi_ca', 'grafi_cl', 'grafi_cr', 'grafi_de', 'grafi_fe', 'grafi_fo', 'grafi_ma',
        'grafi_pa', 'grafi_po', 'grafi_pr', 'grafi_so', 'grafi_tr', 'grafi_urea', // cirugía
        'endosc_diges_super', 'endosc_diges_infer', 'percentiles_paciente',           // ORL
        'ultra_renal', 'ultra_vias_urinarias',                                       // urología, ORL
        'ultra_tiroides',                                                            // ORL
        'interven_consulta', 'examenes_paciente_consulta', 'pre_aneste',
        'riesgo', 'riesgo_paciente', 'riesgo_paciente_opera', 'presupuesto',
    ],

    /*
    | Tablas de la instalación que NO se migran. Quedan declaradas a propósito, para que el diff no las
    | confunda con faltantes.
    */
    'locales' => [
        'usuario', 'usuarios', 'operadores', 'activar_serial1_ddr', 'activarddr',
        'registro_operaciones', 'dieta_paciente_copy', 'pbcat', 'pbcattbl', 'pbcatcol', 'pbcatfmt',
        'pbcatvld', 'pbcatedt',
    ],

    /*
    | Lo que el sync del escritorio necesita saber de esta especialidad (WEB-7.3).
    |
    | `tablas`: las que el escritorio de pediatría sube ADEMÁS de la lista blanca histórica
    | (`config/sync_legado.php` → `tablas`), que ya trae el núcleo y lo compartido con ginecología.
    | Son las 56 que el API no tenía y se crearon el 2026-10-13 (`2026_10_13_100000_create_tablas_pediatria`).
    | De ellas, 42 las escribe el escritorio (DataWindows con `update=` y SQL de las ventanas); las 14
    | restantes son las tablas de referencia de percentiles y de vacunación que se leen, y se incluyen
    | porque la web las va a necesitar para el módulo de crecimiento (WEB-7.4.3).
    |
    | `columnas_equivalentes`: el escritorio llama distinto a una columna que el API ya tiene. **No** se
    | agrega una columna nueva: el sync traduce el nombre, para que las filas de pediatría no queden con
    | la clave en un lado y las pantallas leyendo el otro.
    */
    'sync' => [
        'tablas' => [
            // Paciente pediátrico y su familia
            'paciente_chamo', 'familia_bebe',
            // Crecimiento
            'grafica_peso', 'grafica_talla', 'grafica_peso_talla', 'grafica_imc',
            'grafica_circunferencia_c', 'grafica_brazo', 'grafica_presio_s', 'grafica_presio_d',
            'percentil_nino', 'percentil_nina', 'percentil_pa_sisto_m', 'percentil_pa_diast_m',
            'percentil_pulso_a_m', 'percentiles_paciente',
            // Control de niño sano
            'vacunas', 'vacuna_paciente', 'vacunas_dosis', 'tipo_vacuna', 'vacunas_aplicadas',
            'vacunas_compra', 'vacunas_compradas', 'vacunas_salidas',
            'alertas', 'alertas_paciente', 'tareas', 'tareas_paciente', 'dietas', 'cuestionario',
            // Otras pantallas de esta versión
            'espiro', 'endosc_diges_super', 'endosc_diges_infer', 'examenes_paciente_consulta',
            'grafi_ca', 'grafi_cl', 'grafi_cr', 'grafi_de', 'grafi_fe', 'grafi_fo', 'grafi_ma',
            'grafi_pa', 'grafi_po', 'grafi_pr', 'grafi_so', 'grafi_tr', 'grafi_urea',
            'interven_consulta', 'pre_aneste', 'presupuesto', 'riesgo', 'riesgo_paciente',
            'riesgo_paciente_opera', 'ultra_renal', 'ultra_tiroides', 'ultra_vias_urinarias',
        ],

        'columnas_equivalentes' => [
            // La misma tabla con otra clave: el API la llavea por `historia` + `consulta` (como la
            // escriben la web y ginecología) y el escritorio de pediatría por `nrohistoria` + `nroconsulta`.
            'examen_fisico_nuevo' => [
                'nrohistoria' => 'historia',
                'nroconsulta' => 'consulta',
            ],
        ],

        /*
        | **De dónde salen los pacientes de esta especialidad** (2026-10-14).
        |
        | Ginecología y las demás especialidades los tienen en `pacientes` (la tabla del legado
        | compartido, que ya está en la lista histórica). Pediatría **no la usa**: PediaReport es otro
        | producto y su ABM de pacientes es `paciente_chamo` — el chamo con su familia y su
        | representante (`d_a_pacientes_chamo.srd`; `d_a_pacientes.srd` existe y **ninguna ventana de
        | pediatría lo usa**)—, mientras su `pacientes` está vacía. Medido el 2026-10-14 sobre las bases
        | reales: `pacientes` = **0** filas contra **6.892** en `paciente_chamo` (y las otras seis
        | especialidades sí tienen miles en `pacientes`).
        |
        | Sin esto, la carga sube los chamos a la tabla `paciente_chamo` del API —donde nadie los lee— y
        | el médico queda **sin pacientes y sin historias**: sin historia no hay ficha, no se puede abrir
        | una consulta y el app móvil abre en blanco. Pasó de verdad: la carga de pediatría terminó
        | "Completa" con 1,19 M de filas y 0 pacientes.
        |
        | `columnas` traduce los nombres (escritorio => API) para armar la fila de `pacientes`; lo que no
        | esté mapeado se queda en la tabla `paciente_chamo` del API, que se sigue subiendo entera.
        */
        'pacientes' => [
            'tabla' => 'paciente_chamo',
            'columnas' => [
                'historia'     => 'numhistoria',
                'nombre'       => 'nombres',
                'f_nacimiento' => 'fnacimiento',
                'l_nacimiento' => 'lnacimiento',
                'f_ingreso'    => 'fingreso',
                'celular'      => 'telefono',
                'segu_empre'   => 'codesegemp',
                // `cedula`, `sexo`, `escolaridad` y `email` se llaman igual en las dos tablas.
                // Quedan sin mapear (y por lo tanto viven en `paciente_chamo`): `familia`, `representante`,
                // `foto_paciente`, `nota_general`, `alergia`, `notas`, `sitio`, `medio*`, `pediatrico`,
                // `vacunas` y `medico`. El representante, la alergia y la foto son la Fase 2 del mapeo.
            ],
        ],
    ],

    /*
    | Ventanas del PowerBuilder por módulo: semilla de la matriz de paridad (PLAN-WEB.md §7). El listado
    | completo (309 ventanas) y su estado se llevan aparte; acá van las canónicas de lo propio de pediatría.
    */
    'pantallas' => [
        'crecimiento' => [
            'w_percentiles_fundacredesa',
            'w_visualizar_solicitudes_graficas_peso_m', 'w_visualizar_solicitudes_graficas_peso_f',
            'w_visualizar_solicitudes_graficas_tall_m', 'w_visualizar_solicitudes_graficas_tall_f',
            'w_visualizar_solicitudes_graficas_imc_m', 'w_visualizar_solicitudes_graficas_imc_f',
            'w_visualizar_solicitudes_graficas_cefa_m', 'w_visualizar_solicitudes_graficas_cefa_f',
            'w_visualizar_solicitudes_graficas_p_t_m', 'w_visualizar_solicitudes_graficas_p_t_f',
            'w_visualizar_solicitudes_graficas_pa', 'w_visualizar_solicitudes_graficas_total',
        ],
        'vacunas' => [
            'w_agregar_vacunas', 'w_agregar_dosis_vacunas', 'w_agregar_tipo_vacunas',
            'w_agregar_vacunas_cantidad', 'w_nuevo_vacuna', 'w_buscar_vacunas',
            'w_buscar_vacunas_consulta', 'w_buscar_vacunas_inventario', 'w_compra_vacunas',
            'w_vcompra_vacunas', 'w_escoger_razon_vacuna', 'w_pacientes_vacuven',
        ],
        'pacientes' => ['w_agregar_pacientes_chamos', 'w_agregar_pacientes_chamos_nuevos'],
        'consulta' => [
            'w_consulta_principal', 'w_consulta_principal_modificar',
            'w_escoger_cargar_fisico_laboratorio', 'w_escoger_cargar_fisico_laboratorio_enfe',
        ],
    ],
];
