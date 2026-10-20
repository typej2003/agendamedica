<?php

/*
|--------------------------------------------------------------------------
| Sync del escritorio PowerBuilder (legado) -> API
|--------------------------------------------------------------------------
|
| Lista blanca de las tablas del legado que el escritorio puede subir. Es la ÚNICA puerta: el
| endpoint genérico viejo (`/sync/upload-batch`) escribía en cualquier tabla que existiera en MySQL
| (`users`, `sync_credentials`...). Una tabla nueva del legado se sube recién cuando se agrega acá.
|
| `tablas` es la lista **histórica**: se armó cruzando las tablas de la base local del escritorio de
| ginecología (`gidata.db`, dueño `tera`) con las del API (2026-09-27), y es la que usan ginecología y
| las demás especialidades que ya sincronizaban. Quedaron afuera las que no son datos del médico:
| catálogos internos de PowerBuilder (`pbcat*`), tablas de ejemplo de Sybase (`employee`,
| `department`), las de MobiLink/sistema (dueño `dbo`/`SYS`), `upload_servers` (bitácora del botón
| viejo), `registro_operaciones` y `consultas_subida` (bitácoras), `evolucion_copy` (copia de respaldo
| de `evolucion`) y `operadores`: son los USUARIOS del escritorio, con la contraseña en texto plano
| (sacada el 2026-09-28; la carga inicial la había subido).
|
| **Multi-especialidad (WEB-7.3).** Lo que agrega cada especialidad sale de SU manifiesto
| (`config/especialidades/<slug>.php` → `sync.tablas` y `sync.columnas_equivalentes`), no de otra lista
| escrita a mano acá: sumar una especialidad es agregar su manifiesto, no tocar el motor del sync.
| `tablas_por_especialidad` y `columnas_equivalentes` se derivan de ahí, y `tablas` sigue siendo la
| lista histórica. `App\Sync\Escritorio\TablasDelSync` es quien las combina.
|
| ⚠️ Una tabla que el manifiesto declara pero que el API **no** tiene en la base no se puede ofrecer:
| el sync la rechazaría o escribiría en el vacío. Por eso lo que el manifiesto suma son solo tablas
| que existen en el API (sus migraciones las crean).
|
*/

/*
| Los manifiestos se leen acá una vez: de ellos salen las tablas y las equivalencias de columnas que
| cada especialidad agrega. Se leen con `require` directo (no con `config()`) para que este archivo no
| dependa de que el resto de la configuración ya esté cargada.
*/
$tablasPorEspecialidad = [];
$columnasEquivalentes = [];
$pacientesPorEspecialidad = [];

foreach (glob(__DIR__ . '/especialidades/*.php') ?: [] as $archivoManifiesto) {
    $slug = basename($archivoManifiesto, '.php');
    $manifiesto = require $archivoManifiesto;

    $tablasPorEspecialidad[$slug] = array_values(array_unique((array) ($manifiesto['sync']['tablas'] ?? [])));

    foreach ((array) ($manifiesto['sync']['columnas_equivalentes'] ?? []) as $tabla => $equivalencias) {
        $columnasEquivalentes[$tabla] = (array) $equivalencias;
    }

    // De dónde salen los pacientes de esa especialidad (ver `sync.pacientes` en su manifiesto). Sólo
    // declaran las que NO los tienen en `pacientes`; las demás siguen con la tabla histórica.
    if (($declarado = $manifiesto['sync']['pacientes'] ?? null) && ! empty($declarado['tabla'])) {
        $pacientesPorEspecialidad[$slug] = [
            'tabla'    => (string) $declarado['tabla'],
            'columnas' => (array) ($declarado['columnas'] ?? []),
        ];
    }
}

return [

    // Tablas que se aceptan (101). 35 se crearon el 2026-09-27 desde el dump del legado porque el
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
        'motivo_factura_prov', 'motivos_consulta', 'paciente_no_regi', 'pago_quiru',
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

    // Lo que **agrega cada especialidad** a la lista de arriba (slug del manifiesto => tablas). Se
    // deriva de `config/especialidades/*.php`; ver `App\Sync\Escritorio\TablasDelSync`.
    'tablas_por_especialidad' => $tablasPorEspecialidad,

    // Nombres de columna del escritorio que en el API se llaman distinto (tabla => [escritorio => API]).
    // Se aplican al filtrar una fila y al resolver la clave de un cambio, así la fila no queda con la
    // clave en un lado y las pantallas leyendo el otro (el caso de `examen_fisico_nuevo` en pediatría).
    'columnas_equivalentes' => $columnasEquivalentes,

    // De qué tabla del escritorio salen los PACIENTES, por especialidad (slug => ['tabla', 'columnas']).
    // Se deriva de `sync.pacientes` de cada manifiesto. Lo normal —y lo que hace `TablasDelSync` cuando
    // una especialidad no lo declara— es `pacientes`; pediatría declara `paciente_chamo`, que es su ABM
    // real de pacientes (ver el comentario largo en su manifiesto). `pacientes` siempre se acepta: un
    // médico con dos especialidades tiene las dos tablas y las dos son fuente de pacientes.
    'pacientes_por_especialidad' => $pacientesPorEspecialidad,

    // Columnas que NO se suben aunque vengan en el lote.
    //  - evolucion: credenciales de SMS y la contraseña del sistema viejo; y `logo`, que en el
    //    escritorio es una ruta de Windows y en el API es una ruta de Storage que usa el app
    //    (ver PENDIENTES-POWERBUILDER.md, "Logo del membrete").
    'columnas_excluidas' => [
        'evolucion' => ['contrasena', 'sms_user', 'sms_clave', 'logo'],
    ],

    // Tablas SIN clave primaria en el escritorio en las que el app TAMBIÉN crea filas. Cuando cambian
    // en el escritorio, el escritorio manda la tabla entera y el API reemplaza la del médico: estas filas
    // del app (anotadas en `sync_changes` como `created` con este `table_name`) no se borran.
    // tabla del legado => table_name con que el app anota la creación.
    'sin_clave_con_altas_del_app' => [
        'recipe_detalle' => 'recipes', // récipes creados desde "Atender" (App\Sync\CreacionesClinicas)
        // Las filas del récipe de la pareja que crea la web (WEB-5.1b, `GuardarRecipeDeLaPareja`): `recipes_pareja`
        // tampoco tiene clave primaria en el PowerBuilder (su registro de cambios es de sentencia, `op = T`) y
        // cada fila anota su propio `created` con su id (la tabla no tiene cabecera).
        'recipes_pareja' => 'recipes_pareja',
        // Los tratamientos del catálogo que crea la web (WEB-8.1a, `GuardarTratamiento`): `recipe_grupo` y
        // `recipe_grupo_detalle` tampoco tienen clave primaria en el PowerBuilder (cada una tiene sólo el trigger
        // de sentencia, `op = T`), y cada fila —encabezado y medicamento— anota su propio `created` bajo el
        // nombre de su tabla. Sin estas dos líneas, la siguiente subida del escritorio borraría los tratamientos
        // de la web. `vademecum`, en cambio, SÍ tiene clave primaria y se sube por fila: no entra acá.
        'recipe_grupo' => 'recipe_grupo',
        'recipe_grupo_detalle' => 'recipe_grupo_detalle',
        // Los motivos de cita que crea la web (WEB-8.1c, `CrearMotivoDeCita`): `motivo_cita` no tiene clave primaria en el
        // PowerBuilder, sólo un índice único llamado `primarykey` (`f_agregar_datos.srf:1697`), así que su registro de cambios
        // es de sentencia (`op = T`) y cada subida reemplaza la tabla entera del médico. Sin esta línea esa subida borraría
        // los motivos que creó la web. Cada fila anota su `created` bajo `motivo_cita`.
        'motivo_cita' => 'motivo_cita',
        // La dieta / recomendaciones que crea la web (WEB-5.3b, `GuardarDietaDeLaConsulta`): `dieta_paciente` no tiene clave primaria en
        // el PowerBuilder, sólo un índice único sobre (histroria, consulta), y su registro de cambios es de sentencia (`op = T`:
        // `tr_ddrsync_dieta_paciente`, sin los `_ins/_upd/_del`), así que cada subida reemplaza la tabla entera del médico. Sin esta línea
        // esa subida borraría las dietas que creó la web. Cada fila anota su `created` bajo `dieta_paciente`. (`hospitalizacion`, en
        // cambio, SÍ tiene clave primaria y se sube por fila: no entra acá.)
        'dieta_paciente' => 'dieta_paciente',
        // Las plantillas de texto que crea la web (WEB-8.4, `CrearPlantillaDeTexto`): `formato_print` tampoco tiene clave primaria en el
        // PowerBuilder (sólo `CREATE UNIQUE INDEX primarykey … (codigo)`, `f_agregar_datos.srf:1684`) y su registro de cambios es de
        // sentencia (`tr_ddrsync_formato_print`, `op = T`, sin los `_ins/_upd/_del`), así que cada subida reemplaza la tabla entera del
        // médico. Sin esta línea esa subida borraría las plantillas que creó la web. Cada fila anota su `created` bajo `formato_print`.
        'formato_print' => 'formato_print',
        // Los ítems de la pre-factura que crea la web (F9, `GuardarItemDePreFactura`). **No está relevado** si
        // `his_con_pre_factura` tiene clave primaria en el PowerBuilder (`id_registro` lo sugiere): si la tiene, se sube por
        // fila y esta línea no se usa nunca; si no, evita que la subida de la tabla entera borre las filas de la web.
        'his_con_pre_factura' => 'his_con_pre_factura',
    ],

    // Columnas que el escritorio NO conoce y que el reemplazo de una tabla sin clave no debe borrar (WEB-8.1c). Al reemplazar
    // `motivo_cita` el escritorio manda sólo `codigo` y `tipo_atencion`: sin esto, cada subida dejaría los motivos que vienen
    // del escritorio sin el precio, la sede ni la marca de varias citas que la web les puso. Por cada fila que llega se
    // restauran esas columnas desde la fila que tenía el mismo `clave` (la que no tiene sede, si hay varias).
    'columnas_del_app_en_el_reemplazo' => [
        'motivo_cita' => [
            'clave' => 'codigo',
            'columnas' => ['office_id', 'precio', 'admite_varias_citas_pendientes'],
        ],
    ],

    // Tablas del escritorio que el app guarda en su copia local con el mismo nombre. Cuando el
    // escritorio borra una fila de estas, el API lo anota en `sync_changes` para que el teléfono
    // también la borre. Solo estas: un `eliminados` de una tabla que el app no tiene no le sirve.
    // (`pacientes`, `consultas` y `cola` tienen su propio camino en `CambiosEscritorio`.)
    'borrados_al_app' => [
        'recipes', 'vademecum',
        // Documentos que el app imprime (Paso 25) y los catálogos que usan.
        'constancia_obs', 'reposo_paciente', 'referencia', 'informe', 'diagnostico_paciente',
        'diagnosticos', 'doctores', 'especial',
        // Motivos de consulta (Paso 18.B2): el catálogo y los de cada consulta.
        'motivos_consulta', 'motivo_consulta_paciente',
    ],

    // Tablas que NO cuentan para decidir si el médico está "limpio" (primera carga). `evolucion`
    // es la configuración del médico y puede existir desde que se registró en el app.
    'no_cuentan_para_limpieza' => ['evolucion'],

];
