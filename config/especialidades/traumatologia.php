<?php

/*
|--------------------------------------------------------------------------
| Manifiesto de la especialidad TRAUMATOLOGÍA (AppDDR)
|--------------------------------------------------------------------------
|
| Ver PLAN-WEB.md, regla R2, y el relevamiento `Docs/Web/relevamiento-traumatologia.md` (2026-11-07).
|
| El escritorio es **TraumaReport** (instalación `miconsultat`): su consulta es **la genérica** de AppDDR y su única
| pieza propia es la **plantilla ortopédica** (`ortopedia`: botas, zapatos, soporte, contrafuerte, tacón, suela, bordes,
| aparatos y observación), con su hoja `d_ortopedia.srd`.
|
| La base de la instalación está **archivada**: el `codeespecial` de su catálogo `especial` no se pudo leer, así que
| `codigos_legado` va vacío. El sync toma la especialidad del médico (`medico_specialty`), no del código.
|
*/

return [

    'nombre' => 'Traumatología',

    // Código corto de AppDDR (el de `specialties.codigo`).
    'codigo' => 'ORT',

    // Fila del catálogo `specialties` (la siembra el `SpecialtySeeder` con este nombre).
    'specialty_slug' => 'traumatologia',

    'codigos_legado' => [],
    'nombres_legado' => ['TRAUMATOLOGIA'],

    /*
    | Módulos clínicos. Traumatología no tiene módulos de menú propios: lo suyo es un ítem de la **consulta**
    | (`Ortopedia`) y las solicitudes imprimibles, que se encienden por especialidad en `SeccionesDeLaConsulta`.
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
        // La plantilla ortopédica de la consulta (clave `nrohistoria` + `nroconsulta`).
        'ortopedia',
    ],

    /*
    | Tablas que usan varias especialidades. La consulta del traumatólogo es la genérica: motivos, antecedentes,
    | diagnóstico, evolución, récipes y solicitudes ya están migrados por las otras especialidades.
    */
    'compartidas' => [],

    /*
    | Tablas de la instalación que NO se migran: las pseudo-tablas del PowerBuilder (`P_ANTE`, `P_DIAGNO`…) y
    | `GSISTEMA`. `customer`, `recipe_obs` y `categoria` son de facturación/récipes: se deciden con esa ola.
    */
    'locales' => [
        'usuario', 'usuarios', 'operadores', 'activar_serial1_ddr', 'activarddr',
        'registro_operaciones', 'evolucion_copy', 'recipes_copy',
        'pbcat', 'pbcattbl', 'pbcatcol', 'pbcatfmt', 'pbcatvld', 'pbcatedt',
        'GSISTEMA', 'P_ANTE', 'P_DIAGNO', 'P_EPECIAL', 'P_EXAMEN', 'P_MOTIC', 'P_SEG',
        'P_TIPOE', 'PALM_LISTA', 'PANTECE', 'PCOLA', 'PCONSULT', 'PDIAGNO', 'PMOTI_CO',
        'PPACIENT', 'PRECIPE', 'PPROCEDI', 'PVADEME',
    ],

    /*
    | Lo que el escritorio de traumatología sube ADEMÁS de la lista histórica (`config/sync_legado.php` → `tablas`).
    */
    'sync' => [
        'tablas' => [
            'ortopedia',
        ],
    ],

    /*
    | Ventanas del PowerBuilder por módulo: semilla de la matriz de paridad (PLAN-WEB.md §7).
    */
    'pantallas' => [
        'consulta' => [
            'w_consulta_principal', 'w_consulta_principal_modificar',
            // Las imágenes del segmento posterior (propias).
            'w_imagen_segmento_posterior', 'w_imagen_segmento_posterior3', 'w_imagen_segmento_posterior_m',
            'w_imagen_amplia',
        ],
        'solicitudes' => [
            'w_visualizar_solicitudes_implante_c', 'w_visualizar_solicitudes_implante_r',
            'w_visualizar_solicitudes_implante_pt', 'w_visualizar_solicitudes_orden_ingreso',
            'w_visualizar_solicitudes_informe',
        ],
    ],
];
