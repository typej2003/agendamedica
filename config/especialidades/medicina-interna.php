<?php

/*
|--------------------------------------------------------------------------
| Manifiesto de la especialidad MEDICINA INTERNA (AppDDR)
|--------------------------------------------------------------------------
|
| Ver PLAN-WEB.md, regla R2, y el relevamiento `Docs/Web/relevamiento-medicina-interna.md` (2026-10-28).
|
| El escritorio es **MedicReport** (instalación `miconsultac`, workspace `cirugia.pbt`, base `DATACI.db`). ⚠️ Los
| documentos del relevamiento multi-especialidad la llamaban «cirugía» (`esquemas-asa/cirugia.sql`,
| `fuentes-cirugia/`): es esta. Lo que sale de ahí:
|  - tablas: el esquema real (111 tablas) y las filas medidas en la base de un internista real (sólo cantidades);
|  - ventanas: `Docs/MICONSULTAGI-legado/fuentes-cirugia/` (la consulta es `w_consulta_principal.srw`);
|  - código del legado: catálogo `especial` de esa base (`020` = "MEDICINA INTERNA").
|
| ⚠️ El `codeespecial` es **de cada instalación**: en la de ginecología el `020` es "GINECOLOGIA Y OBSTETRICIA". Hoy
| nadie identifica la instalación por código (`RegistroEspecialidades::porNombreLegado` no tiene llamadores): el sync
| toma la especialidad del médico (`medico_specialty`). Si algún día se usa, que sea con el nombre, no con el código.
|
*/

return [

    'nombre' => 'Medicina Interna',

    // Código corto de AppDDR (el de `specialties.codigo`).
    'codigo' => 'MIN',

    // Fila del catálogo `specialties` (la siembra el `SpecialtySeeder` con este nombre).
    'specialty_slug' => 'medicina-interna',

    'codigos_legado' => ['020'],
    'nombres_legado' => ['MEDICINA INTERNA', 'INTERNISTA', 'MEDICO INTERNISTA'],

    /*
    | Módulos clínicos. Medicina interna no tiene módulos de menú propios: lo suyo son ítems de la **consulta**
    | (evaluación física, EKG, análisis evolutivo, evaluación pre-operatoria) y las solicitudes imprimibles, que se
    | encienden por especialidad en `SeccionesDeLaConsulta` / `AlcanceDeLaEspecialidad`.
    */
    'modulos' => [
        // Núcleo
        'agenda', 'pacientes', 'historia', 'consulta', 'recetas', 'documentos',
        'catalogos', 'configuracion', 'reportes',
    ],

    /*
    | Tablas que sólo usa esta especialidad.
    */
    'propias' => [
        // «Análisis evolutivo»: los valores de laboratorio de cada consulta (una fila por consulta).
        'datos_grafica',
    ],

    /*
    | Tablas que usan varias especialidades (al lado, dónde más aparecen en los esquemas reales).
    */
    'compartidas' => [
        'ekg',                                                        // cardiología, ginecología
        'pre_trombo', 'pre_infec', 'pre_espiro', 'pre_espec',         // cardiología
        'pre_aneste', 'riesgo', 'riesgo_paciente', 'riesgo_paciente_opera', // cardiología, pediatría
    ],

    /*
    | Tablas de la instalación que NO se migran. `ortopedia`: la solapa «Ortopedia infantil» no está en los menús del
    | escritorio (decisión del usuario, 2026-10-28: lo que no está en los menús se omite); `cumanaoca` e `impuestos`
    | no tienen uso.
    */
    'locales' => [
        'usuario', 'usuarios', 'operadores', 'activar_serial1_ddr', 'activarddr',
        'registro_operaciones', 'evolucion_copy', 'recipes_copy',
        'pbcat', 'pbcattbl', 'pbcatcol', 'pbcatfmt', 'pbcatvld', 'pbcatedt',
        'ortopedia', 'cumanaoca', 'impuestos',
    ],

    /*
    | Lo que el escritorio de medicina interna sube ADEMÁS de la lista histórica (`config/sync_legado.php` →
    | `tablas`). Los pacientes salen de `pacientes`, como en ginecología.
    */
    'sync' => [
        'tablas' => [
            'ekg', 'datos_grafica',
            'pre_aneste', 'pre_trombo', 'pre_infec', 'pre_espiro', 'pre_espec',
            'riesgo', 'riesgo_paciente', 'riesgo_paciente_opera',
        ],
    ],

    /*
    | Ventanas del PowerBuilder por módulo: semilla de la matriz de paridad (PLAN-WEB.md §7).
    */
    'pantallas' => [
        'consulta' => [
            'w_consulta_principal', 'w_consulta_principal_modificar',
        ],
        'solicitudes' => [
            'w_visualizar_solicitudes_h_cardiaco', 'w_visualizar_solicitudes_h_presion',
            'w_visualizar_solicitudes_esfuerzo', 'w_visualizar_solicitudes_stres',
            'w_visualizar_solicitudes_dd_eco_ete', 'w_visualizar_solicitudes_dd_ecografia',
            'w_visualizar_solicitudes_dd_arterial', 'w_visualizar_solicitudes_dd_venoso',
            'w_visualizar_solicitudes_implante_c', 'w_visualizar_solicitudes_implante_r',
            'w_visualizar_solicitudes_lekg', 'w_visualizar_solicitudes_orden_ingreso',
            'w_visualizar_solicitudes_informe_media', 'w_visualizar_solicitudes_reposos_a',
        ],
        'analisis_evolutivo' => [
            'w_visualizar_solicitudes_grafica', 'w_visualizar_solicitudes_graficas_total',
        ],
    ],
];
