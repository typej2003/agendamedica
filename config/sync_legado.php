<?php

/*
|--------------------------------------------------------------------------
| Sync del escritorio PowerBuilder (GinecoReport) -> API
|--------------------------------------------------------------------------
|
| Lista blanca de las tablas del legado que el escritorio puede subir. Es la ÚNICA puerta: el
| endpoint genérico viejo (`/sync/upload-batch`) escribía en cualquier tabla que existiera en MySQL
| (`users`, `sync_credentials`...). Una tabla nueva del legado se sube recién cuando se agrega acá.
|
| Se armó cruzando las tablas de la base local del escritorio (`gidata.db`, dueño `tera`) con las
| tablas del API (2026-09-27). Quedaron afuera las que no son datos del médico: catálogos internos
| de PowerBuilder (`pbcat*`), tablas de ejemplo de Sybase (`employee`, `department`), las de
| MobiLink/sistema (dueño `dbo`/`SYS`), `upload_servers` (bitácora del botón viejo),
| `registro_operaciones` y `consultas_subida` (bitácoras) y `evolucion_copy` (copia de respaldo de `evolucion`).
|
*/

return [

    // Tablas que se aceptan (102). 35 se crearon el 2026-09-27 desde el dump del legado porque el
    // API no las tenía (ver la migración create_tablas_legado_faltantes).
    'tablas' => [
        // `pacientes` va siempre primero (crea el paciente, la relación con el médico y la historia,
        // que el resto de las tablas referencian por número de historia).
        'pacientes',
        'antece_paciente', 'antecedentes', 'bancos', 'baremo_quiru', 'clinicas', 'cola',
        'cola_dia_no_labor', 'constancia_obs', 'consultas', 'consultorios', 'cuentas_x_pagar',
        'cuentas_x_pagar_mov', 'dd_arterial_mi', 'detalles_factura_cliente',
        'detalles_presupuesto_plantilla', 'diagnostico_paciente', 'diagnosticos', 'dias_semana',
        'dieta_paciente', 'doctores', 'eco_doppler', 'eco_obstetrico', 'eco_obstetrico_tercer',
        'eco_obstetrico_tercer_2', 'eco_obstetrico_tercer_2_o', 'eco_obstetrico_tercer_o',
        'eco_pelvico', 'ecocadiograma_fetal', 'emision_pagos', 'emision_pagos_detalle', 'especial',
        'evolucion', 'examen_fisico', 'examen_fisico_nuevo', 'examen_obs', 'examen_paciente',
        'examen_pareja', 'examenes', 'factura_cliente', 'facturas_compras',
        'facturas_compras_detalle', 'formato_print', 'his_con_pre_factura', 'hospitalizacion',
        'imagen_consulta', 'imagen_pacientes', 'imagen_pacientes_2', 'imagenes', 'informe',
        'intenven_servi', 'listado', 'motivo_cita', 'motivo_consulta_paciente', 'motivo_factura',
        'motivo_factura_prov', 'motivos_consulta', 'operadores', 'paciente_no_regi', 'pago_quiru',
        'pre_natal_desarrollo', 'pre_natal_desarrollo_fino', 'pre_natal_examenes',
        'pre_natal_observaciones', 'prena_exames_b', 'presupuesto_operatorio', 'presupuesto_planti',
        'proveedor', 'radiologia_obs', 'radiologia_paciente', 'radiologias', 'recipe2',
        'recipe_detalle', 'recipe_grupo', 'recipe_grupo_detalle', 'recipes', 'recipes_pareja',
        'referencia', 'reg_empl_frec_nomina', 'reg_empl_tipo_nomina', 'registro_empleados',
        'registro_empleados_eje', 'registro_empleados_eje_detalle', 'reposo_paciente',
        'representante', 'seg_emp', 'sms_compra', 'sms_enviados', 'sms_envio_pac', 'texto_doppler',
        'tipo_antecedente', 'tipos_conceptos', 'tipos_documentos', 'tipos_examenes', 'tipos_recipe',
        'ultra_abdominal', 'ultra_mama', 'ultra_prostatico', 'ultra_testiculos',
        'ultra_tiroides_musculo', 'vademecum', 'vademecum_m',
    ],

    // Columnas que NO se suben aunque vengan en el lote.
    //  - evolucion: credenciales de SMS y la contraseña del sistema viejo; y `logo`, que en el
    //    escritorio es una ruta de Windows y en el API es una ruta de Storage que usa el app
    //    (ver PENDIENTES-POWERBUILDER.md, "Logo del membrete").
    'columnas_excluidas' => [
        'evolucion' => ['contrasena', 'sms_user', 'sms_clave', 'logo'],
    ],

    // Tablas que NO cuentan para decidir si el médico está "limpio" (primera carga). `evolucion`
    // es la configuración del médico y puede existir desde que se registró en el app.
    'no_cuentan_para_limpieza' => ['evolucion'],

];
