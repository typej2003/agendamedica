<?php

/*
|--------------------------------------------------------------------------
| Manifiesto de la especialidad CARDIOLOGÍA (AppDDR)
|--------------------------------------------------------------------------
|
| Ver PLAN-WEB.md, regla R2, y el relevamiento `Docs/Web/relevamiento-cardiologia.md` (2026-11-04).
|
| El escritorio es **CardioReport** (instalación `miconsultaca`, workspace `cardiologia.pbt`, base
| `datosca/CARDATA.db`, DSN `cardata`). Lo que sale de ahí:
|  - tablas: el esquema real (127 tablas) en `Docs/MICONSULTAGI-legado/esquemas-asa/cardiologia.sql`;
|  - ventanas: `Docs/MICONSULTAGI-legado/fuentes-cardiologia/` (la consulta es `w_consulta_principal.srw`);
|  - código del legado: catálogo `especial` de esa base (`002` = "CARDIOLOGIA").
|
| ⚠️ El `codeespecial` es **de cada instalación**: en la de ginecología el `002` no es cardiología. Hoy
| nadie identifica la instalación por código (`RegistroEspecialidades::porNombreLegado` no tiene llamadores):
| el sync toma la especialidad del médico (`medico_specialty`). Si algún día se usa, que sea con el nombre.
|
*/

return [

    'nombre' => 'Cardiología',

    // Código corto de AppDDR (el de `specialties.codigo`).
    'codigo' => 'CAR',

    // Fila del catálogo `specialties` (la siembra el `SpecialtySeeder` con este nombre).
    'specialty_slug' => 'cardiologia',

    'codigos_legado' => ['002'],
    'nombres_legado' => ['CARDIOLOGIA', 'CARDIOLOGÍA'],

    /*
    | Módulos del menú. Cardiología atiende con el núcleo; lo suyo son los estudios de la consulta
    | (ecocardiograma, Doppler, esfuerzo, oscilometría, marcapasos, implantes) y los imprimibles, que se
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
        // Estudios de la consulta (una fila por consulta).
        'oscilometria',
        'implante', 'implantacion_marcapasos',
        // Solicitudes y adjuntos.
        'orden_ingreso', 'archivos_historia',
        // Plantillas de los informes.
        'texto_cardiaco', 'texto_reporte',
    ],

    /*
    | Tablas que usan varias especialidades (al lado, dónde más aparecen en los esquemas reales).
    |
    | `dd_arterial_mi` no se declara acá: ginecología la tiene como **propia** y el validador rechaza que
    | cardiología la declare compartida. Igual ya existe en el API y viaja en la lista histórica del sync.
    */
    'compartidas' => [
        'ekg',                                                          // medicina interna, ginecología
        'pre_aneste', 'pre_trombo', 'pre_infec', 'pre_espiro', 'pre_espec', // medicina interna
        'riesgo', 'riesgo_paciente', 'riesgo_paciente_opera',              // medicina interna, pediatría
        'ecocardiografia', 'ecocardiograma_ete', 'ecocardiografico_in', 'prueba_esfuerzo',
        'dd_arterias_carotidas', 'dd_arterias_carotidas_dos', 'dd_arterial_venoso_ms', 'ddflujo_venoso',
        'antecente_fijo',                                                  // ginecología
    ],

    /*
    | Tablas de la instalación que NO se migran: las locales de PowerBuilder y las copias; `ortopedia`
    | (fuera de los menús del escritorio); `textos` (nota de factura); `recipes_psico`; y las de
    | oftalmología, que viven en la librería compartida pero no son de cardiología.
    */
    'locales' => [
        'cumanaoca', 'impuestos', 'ortopedia', 'textos',
        'historia_oftalmologica', 'textos_oftalmologica',
        'pbcat', 'pbcattbl', 'pbcatcol', 'pbcatfmt', 'pbcatvld', 'pbcatedt',
    ],

    /*
    | Lo que el escritorio de cardiología sube ADEMÁS de la lista histórica (`config/sync_legado.php` →
    | `tablas`).
    */
    'sync' => [
        'tablas' => [
            'ekg', 'ecocardiografia', 'ecocardiografico_in', 'ecocardiograma_ete',
            'prueba_esfuerzo', 'oscilometria',
            'dd_arterial_venoso_ms', 'dd_arterias_carotidas_dos', 'ddflujo_venoso',
            'implante', 'implantacion_marcapasos', 'orden_ingreso', 'archivos_historia',
            'texto_cardiaco', 'texto_reporte', 'antecente_fijo',
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
        'estudios' => [
            'w_cargar_visualizar_ecocardiografia', 'w_cargar_visualizar_ete',
            'w_cargar_visualizar_stres', 'w_cargar_visualizar_miembro_s',
            'w_cargar_visualizar_m_s', 'w_cargar_visualizar_venoso', 'w_cargar_visualizar_m_i',
        ],
        'solicitudes' => [
            'w_visualizar_solicitudes_h_cardiaco', 'w_visualizar_solicitudes_h_presion',
            'w_visualizar_solicitudes_esfuerzo', 'w_visualizar_solicitudes_dd_stres',
            'w_visualizar_solicitudes_dd_eco_ete', 'w_visualizar_solicitudes_dd_ecografia',
            'w_visualizar_solicitudes_dd_arterial', 'w_visualizar_solicitudes_dd_venoso',
            'w_visualizar_solicitudes_implante', 'w_visualizar_solicitudes_oscilometria',
            'w_visualizar_solicitudes_lekg', 'w_visualizar_solicitudes_orden_ingreso',
            'w_visualizar_solicitudes_informe_media',
        ],
    ],
];
