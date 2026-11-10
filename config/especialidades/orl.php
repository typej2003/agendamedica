<?php

/*
|--------------------------------------------------------------------------
| Manifiesto de la especialidad OTORRINOLARINGOLOGÍA (AppDDR)
|--------------------------------------------------------------------------
|
| Ver PLAN-WEB.md, regla R2, y el relevamiento `Docs/Web/relevamiento-orl.md` (2026-11-10).
|
| El escritorio es **ORL** (`miconsultaorl`, fuentes en `Docs/MICONSULTAGI-legado/fuentes-orl/` y esquema en
| `esquemas-asa/orl.sql`, 103 tablas). Su consulta tiene **su propio árbol de 13 ítems**: el de todas las
| especialidades, sin estudio ecográfico ni control de embarazo, con **PROCEDIMIENTOS** e **INTERVENCIONES Q.** propios.
|
| Lo propio del ORL, ya relevado, es:
|  - la **evaluación física** por regiones (`fisico_orl`: faringe, orofaringe, laringe, nariz, rinofaringe, oído,
|    neurológico, cuello y tórax) en lugar de la genérica;
|  - la **audiometría** por frecuencia y sus gráficas (`oido_derecho`, `oido_izquierdo`, `oidos_graficas`,
|    `oidos_graficas_iz`, `audio_detalle`);
|  - la **nasofibrolaringoscopía** con sus imágenes (`NASO`, `naso_new`, `naso_new_video`, `imagen_nasolarigo`,
|    `imagen_nasovideo`);
|  - las **intervenciones quirúrgicas** de la consulta (`interven_consulta`, ya migrada) y su catálogo.
|
| ⚠️ **Las once tablas propias todavía NO están migradas** (2026-11-10): van en su migración antes de que el módulo
| tenga pantalla. Por eso `sync.tablas` va vacío: el sync sólo puede subir tablas que existan en MySQL.
|
| `codigos_legado` va vacío: el `codeespecial` del catálogo `especial` de la instalación no se leyó todavía. El sync
| toma la especialidad del médico (`medico_specialty`), no del código.
|
*/

return [

    'nombre' => 'Otorrinolaringología',

    // Código corto de AppDDR (el de `specialties.codigo`).
    'codigo' => 'ORL',

    // Fila del catálogo `specialties` (la siembra el `SpecialtySeeder` con este nombre).
    'specialty_slug' => 'otorrinolaringologia',

    'codigos_legado' => [],
    'nombres_legado' => ['OTORRINOLARINGOLOGIA', 'OTORRINO', 'ORL'],

    /*
    | Módulos clínicos: el núcleo, como en todas. Lo propio del ORL vive en la consulta (sus ítems y su evaluación
    | física) y en sus solicitudes, que se encienden por especialidad en `SeccionesDeLaConsulta`.
    */
    'modulos' => [
        'agenda', 'pacientes', 'historia', 'consulta', 'recetas', 'documentos',
        'catalogos', 'configuracion', 'reportes',
    ],

    /*
    | Tablas que sólo usa esta especialidad (esquema `orl.sql`). **Pendientes de migración.**
    */
    'propias' => [
        // La evaluación física del ORL, una fila por consulta (`historia` + `consulta`).
        'fisico_orl',
        // La audiometría: una fila por frecuencia y oído, y las gráficas del audiograma.
        'oido_derecho', 'oido_izquierdo', 'oidos_graficas', 'oidos_graficas_iz', 'audio_detalle',
        // La nasofibrolaringoscopía y sus imágenes (las rutas son de la PC del consultorio).
        'NASO', 'naso_new', 'naso_new_video', 'imagen_nasolarigo', 'imagen_nasovideo',
    ],

    /*
    | Tablas que comparte con otras especialidades y que ya están migradas: las de las intervenciones quirúrgicas
    | (la consulta y su catálogo, que también usa cirugía pediátrica) y el resto del núcleo.
    */
    'compartidas' => [
        'interven_consulta', 'intenven_servi', 'baremo_quiru', 'pago_quiru',
    ],

    /*
    | Tablas de la instalación que NO se migran: las pseudo-tablas del PowerBuilder (`P_ANTE`, `P_DIAGNO`…),
    | `GSISTEMA` y las copias de trabajo (`evolucion_copy*`, `recipes_copy`).
    */
    'locales' => [
        'usuario', 'usuarios', 'operadores', 'activar_serial1_ddr', 'activarddr',
        'registro_operaciones', 'evolucion_copy', 'evolucion_copy1', 'evolucion_copy3', 'recipes_copy',
        'pbcatcol', 'pbcatedt', 'pbcatfmt', 'pbcattbl', 'pbcatvld', 'GSISTEMA',
        'P_ANTE', 'P_DIAGNO', 'P_ESPECIAL', 'P_EXAMEN', 'P_MOTIC', 'P_SEG', 'P_TIPOE',
    ],

    /*
    | Lo que el escritorio del ORL sube ADEMÁS de la lista histórica (`config/sync_legado.php` → `tablas`).
    | Vacío hasta que sus tablas estén migradas.
    */
    'sync' => [
        'tablas' => [],
    ],

    /*
    | Ventanas del PowerBuilder por módulo: semilla de la matriz de paridad (PLAN-WEB.md §7).
    */
    'pantallas' => [
        'consulta' => [
            'w_consulta_principal', 'w_consulta_principal_modificar',
            // Las imágenes y el video de la consulta.
            'w_imagen_amplia', 'w_video',
        ],
        'intervenciones' => [
            'w_agregar_intervenciones', 'w_buscar_interven', 'w_buscar_intervenciones', 'w_nuevo_intervenciones',
        ],
    ],
];
