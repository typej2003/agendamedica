<?php

/*
|--------------------------------------------------------------------------
| Manifiesto de la especialidad GINECOLOGÍA Y OBSTETRICIA (AppDDR)
|--------------------------------------------------------------------------
|
| Ver PLAN-WEB.md, regla R2. Este archivo es la verdad de **código** de la especialidad: sus módulos,
| sus tablas y las ventanas del PowerBuilder que hay que replicar. La verdad de **configuración**
| (qué módulo está implementado y quién lo ve) vive en `modulos_clinicos` + `especialidad_modulo`.
|
| Los datos de tablas y ventanas salen de fuentes verificadas, no de suposiciones:
|  - tablas: `Docs/MICONSULTAGI-legado/diff-esquemas-especialidades.md` (esquemas reales de las bases)
|    y el inventario de DataWindows de cada versión;
|  - ventanas: `Docs/MICONSULTAGI-legado/fuentes/` (295 ventanas extraídas del .pbl de ginecología);
|  - código del legado: catálogo `especial` del dump (`020` = "GINECOLOGIA Y OBSTETRICIA.").
|
*/

return [

    'nombre' => 'Ginecología y Obstetricia',

    // Código corto de AppDDR (el de `specialties.codigo`).
    'codigo' => 'GIN',

    // Fila del catálogo `specialties` (el directorio público ya usa este slug). Si no existe, el
    // seeder la crea con el nombre del manifiesto.
    'specialty_slug' => 'ginecologia-y-obstetricia',

    // Cómo llama el legado a esta especialidad. El `codeespecial` es del catálogo `especial` de cada
    // instalación (numérico, 001-038) y el nombre varía con puntos y mayúsculas: se comparan normalizados.
    'codigos_legado' => ['020'],
    'nombres_legado' => ['GINECOLOGIA Y OBSTETRICIA', 'GINECOLOGIA', 'OBSTETRICIA', 'GINECO'],

    /*
    | Módulos clínicos (slugs del catálogo `modulos_clinicos`). Los primeros son el núcleo compartido
    | por todas las especialidades; los últimos son propios de esta versión.
    */
    'modulos' => [
        // Núcleo
        'agenda', 'pacientes', 'historia', 'consulta', 'recetas', 'documentos',
        'catalogos', 'configuracion', 'reportes',
        // Propios de ginecología / obstetricia
        'prenatal', 'ecografias', 'ultrasonidos', 'procedimientos', 'radiologia', 'pareja',
    ],

    /*
    | Tablas del módulo: las crea/usa esta especialidad. Si otra especialidad también las usa, van en
    | `compartidas` (el validador falla si la misma tabla es "propia" de dos manifiestos).
    */
    'propias' => [
        // Obstetricia / control prenatal
        'pre_natal_desarrollo', 'pre_natal_desarrollo_fino', 'pre_natal_examenes', 'pre_natal_observaciones', 'prena_exames_b',
        // Ecografías y doppler
        'eco_pelvico', 'eco_obstetrico', 'eco_obstetrico_tercer', 'eco_obstetrico_tercer_o',
        'eco_obstetrico_tercer_2', 'eco_obstetrico_tercer_2_o', 'ecocadiograma_fetal', 'eco_doppler', 'texto_doppler',
        // Ultrasonidos propios de la consulta ginecológica
        'ultra_mama',
        // Consulta de pareja
        'examen_pareja', 'recipes_pareja',
        // Dopper arterial de miembros (lo usan la consulta ginecológica y otras)
        'dd_arterial_mi',
    ],

    /*
    | Tablas que usan varias especialidades: se declaran acá y también en el manifiesto de la otra.
    */
    'compartidas' => [
        'ultra_renal', 'ultra_tiroides', 'ultra_vias_urinarias', 'ultrasonidorenal',
        'ecocardiografia', 'ecocardiograma_ete', 'ecocardiografico_in', 'ekg', 'prueba_esfuerzo',
        'dd_arterias_carotidas', 'dd_arterias_carotidas_dos', 'dd_arterial_venoso_ms', 'ddflujo_venoso',
        'intervenciones', 'interven_consulta', 'trata_insitu', 'trata_insitu_deta',
        'pac_video', 'pacientes_externos', 'recipes_psico',
        'antece_pacientes_3', 'antecente_fijo', 'cola_sh', 'factura_maestro_final', 'segundo_listado',
    ],

    /*
    | Tablas de la instalación que NO se migran: usuarios del escritorio (con su clave en texto plano),
    | licencias y bitácoras. Quedan declaradas a propósito, para que el diff no las confunda con faltantes.
    */
    'locales' => [
        'usuario', 'usuarios', 'operadores', 'activar_serial1_ddr', 'activarddr',
        'registro_operaciones', 'evolucion_copy', 'consultas_subida',
    ],

    // Columnas nullable que AppDDR agrega a tablas legadas (nunca se renombra ni se reestructura).
    'columnas_nuevas' => [
        // (vacío por ahora; se llena cuando un módulo necesite un dato que el legado no guardaba)
    ],

    /*
    | Ventanas del PowerBuilder por módulo: semilla de la matriz de paridad (PLAN-WEB.md §7).
    | El listado completo y su estado se llevan aparte; acá van las canónicas de cada módulo.
    */
    'pantallas' => [
        'agenda' => [
            'w_hacer_cita', 'w_nueva_cita', 'w_modificar_cita', 'w_calendar', 'w_fecha_consulta',
            'w_fecha_consulta_modificar_dia', 'w_sms_enviar', 'w_correo_enviar',
        ],
        'pacientes' => [
            'w_agregar_pacientes', 'w_agregar_pacientes_consulta', 'w_agregar_pacientes_consulta_cita',
            'w_agregar_pacientes_no_regi_cita', 'w_pasar_pacientes_n_reg_consulta_cita', 'w_escoger_pac_h_sh',
        ],
        'consulta' => [
            'w_consulta_principal', 'w_consulta_principal_modificar', 'w_base', 'w_escoger_razon',
            'w_pacientes_a_atender_hoy', 'w_visualizar_solicitudes', 'w_visualizar_solicitudes_laboratorio',
            'w_visualizar_solicitudes_radiologia', 'w_visualizar_solicitudes_reposos',
            'w_visualizar_solicitudes_referencias', 'w_visualizar_solicitudes_dietas',
            'w_visualizar_solicitudes_hospitalizacion', 'w_visualizar_solicitudes_informe',
        ],
        'recetas' => ['w_agregar_notas_recipe', 'w_agregar_nueva_medicamento', 'w_configurar_recipes', 'w_configurar_recipes_2'],
        'prenatal' => [
            'w_agregar_nueva_gesta', 'w_agregar_nueva_gesta_rutina', 'w_agregar_nueva_gesta_rutina_examen',
            'w_agregar_nueva_gesta_rutina_m', 'w_agregar_nueva_gesta_segunda', 'w_agregar_nueva_gesta_recipe_2',
        ],
        'ecografias' => [
            'w_cargar_visualizar_ecografia', 'w_cargar_visualizar_ecografia_obstetri_1',
            'w_cargar_visualizar_ecografia_obtetri_2', 'w_cargar_visualizar_ecografia_obtetri_3',
            'w_cargar_visualizar_eco_doppler', 'w_cargar_visualizar_ecocardiograma_fetal',
            'w_cargar_visualizar_ecografia_vieja',
        ],
        'ultrasonidos' => [
            'w_cargar_visualizar_eco_mamario', 'w_cargar_visualizar_eco_abdominal',
            'w_cargar_visualizar_renal', 'w_cargar_visualizar_prostatico',
            'w_cargar_visualizar_testicular', 'w_cargar_visualizar_eco_musculo',
        ],
        'procedimientos' => ['w_cargar_visualizar_eco_partes_b', 'w_cargar_visualizar_ete', 'w_cargar_visualizar_m_i'],
        'catalogos' => [
            'w_nuevo_diagnostico', 'w_nuevo_vademecum', 'w_nuevo_examen', 'w_nuevo_radiologia',
            'w_nuevo_antecedente', 'w_nuevo_medico', 'w_agregar_motivo_cita',
        ],
        'configuracion' => ['w_configuracion', 'w_horarios', 'w_moneda', 'w_panel_impresion', 'w_panel_impresion_formatos'],
        'reportes' => ['w_reportes_consultas', 'w_pacientes_atendidos_hoy', 'w_reporte_consulta_partos'],
        'administracion' => [
            'w_factura_principal', 'w_pre_factura', 'w_presupuesto_principal', 'w_baremo_quiru',
            'w_pago_consulta_dos', 'w_listado_factura', 'w_listado_presupuesto',
        ],
        'utilidades' => ['w_respaldo', 'w_activar', 'w_serial1', 'w_principal', 'w_manteniento'],
    ],
];
