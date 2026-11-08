<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tablas de **cardiología** (CardioReport, instalación `miconsultacard`) que el API no tenía.
 *
 * Las definiciones salen TAL CUAL del esquema real de esa instalación
 * (`Docs/MICONSULTAGI-legado/esquemas-asa/cardiologia.sql`), con el borrador de
 * `bridge/tools/diff-columnas-especialidades.php --migracion=cardiologia` revisado a mano: mismas columnas,
 * tipos y largos, más `id`, `reg_medico` y fechas como el resto de las tablas del legado.
 *
 * Entran sólo las tablas de los módulos que abre la consulta del cardiólogo (`w_consulta_principal.srw`):
 *  - `ecocardiografia`, `ecocardiografico_in`, `ecocardiograma_ete`: ecocardiograma y sus informes;
 *  - `prueba_esfuerzo`, `oscilometria`: esfuerzo y oscilometría;
 *  - `dd_arterial_venoso_ms`, `dd_arterias_carotidas_dos`, `ddflujo_venoso`: Doppler arterial, venoso y carotídeo;
 *  - `implante`, `implantacion_marcapasos`: implantes y marcapasos;
 *  - `orden_ingreso`: orden de ingreso;
 *  - `texto_cardiaco`, `texto_reporte`: las plantillas de los informes;
 *  - `archivos_historia`: los PDF que adjunta el escritorio (ítem «ARCHIVOS-PDF»);
 *  - `antecente_fijo`: los antecedentes fijos del paciente (riesgo cardiovascular).
 *
 * No entran: `ortopedia` (fuera de los menús), `cumanaoca`, `impuestos`, `recipes_psico` y `textos`
 * (nota de factura), sin uso; ni `historia_oftalmologica` / `textos_oftalmologica`, que son de oftalmología.
 * `ekg`, `pre_*` y `riesgo*` ya existen (medicina interna y pediatría).
 *
 * `hasTable` como el resto: el servidor de desarrollo puede tener alguna creada a mano. `down()` no borra
 * nada (misma nota que `create_tablas_medicina_interna`).
 */
return new class extends Migration
{
    public function up(): void
    {

        // antecente_fijo   (clave del escritorio: numhistoria)
        if (! Schema::hasTable('antecente_fijo')) {
            Schema::create('antecente_fijo', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('numhistoria');
                $table->string('diabetes', 1)->nullable();
                $table->string('tvptep', 1)->nullable();
                $table->string('cardiopatia', 1)->nullable();
                $table->string('tabaquismo', 1)->nullable();
                $table->string('neoplasias', 1)->nullable();
                $table->string('reposoprlongado', 1)->nullable();
                $table->string('varicestvs', 1)->nullable();
                $table->string('traumatismo', 1)->nullable();
                $table->string('hta', 1)->nullable();
                $table->string('cirugia_bypass', 1)->nullable();
                $table->string('endarterectomia', 1)->nullable();
                $table->string('acvamaurosis', 1)->nullable();
                $table->string('actp', 1)->nullable();
                $table->string('amputacion_msls', 1)->nullable();
                $table->string('broncoupulmonares', 100)->nullable();
                $table->string('cardiovasculares', 100)->nullable();
                $table->string('hepaticos', 100)->nullable();
                $table->string('renales', 100)->nullable();
                $table->string('alcoholismo', 100)->nullable();
                $table->string('alergias_medicantosas', 100)->nullable();
                $table->string('otros', 200)->nullable();
                $table->string('amaurosis', 1)->nullable();
                $table->timestamps();
            });
        }
        // dd_arterias_carotidas_dos   (clave del escritorio: numhistoria + numconsulta)
        if (! Schema::hasTable('dd_arterias_carotidas_dos')) {
            Schema::create('dd_arterias_carotidas_dos', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('numhistoria');
                $table->integer('numconsulta');
                $table->string('indicacion', 100)->nullable();
                $table->decimal('referido_por', 15, 0)->nullable();
                $table->string('hc_text', 50)->nullable();
                $table->double('ar_accp_der')->nullable();
                $table->double('ar_accp_izq')->nullable();
                $table->double('ar_accd_der')->nullable();
                $table->double('ar_accd_izq')->nullable();
                $table->double('ar_ace_der')->nullable();
                $table->double('ar_ace_izq')->nullable();
                $table->double('ar_aci_der')->nullable();
                $table->double('ar_aci_izq')->nullable();
                $table->double('ar_aci_acc_der')->nullable();
                $table->double('ar_aci_ace_izq')->nullable();
                $table->double('ar_carotida_interna_der')->nullable();
                $table->double('ar_carotida_interna_izq')->nullable();
                $table->double('av_diametro_der')->nullable();
                $table->double('av_diametro_izq')->nullable();
                $table->double('av_veloci_sp_der')->nullable();
                $table->double('av_veloci_sp_izq')->nullable();
                $table->double('av_direccionf_der')->nullable();
                $table->double('av_direccionf_izq')->nullable();
                $table->text('hallasgos')->nullable();
                $table->string('conclusion', 250)->nullable();
                $table->string('equipo', 60)->nullable();
                $table->string('transductor', 60)->nullable();
                $table->string('segunda_armonica', 1)->nullable();
                $table->string('contaste', 1)->nullable();
                $table->text('modo_b')->nullable();
                $table->text('modo_color')->nullable();
                $table->string('llenado', 1)->nullable();
                $table->double('relac_intima_media')->nullable();
                $table->double('relac_intima_media_d')->nullable();
                $table->text('full_text')->nullable();
                $table->timestamps();
            });
        }
        // ddflujo_venoso   (clave del escritorio: numhistoria + numconsulta)
        if (! Schema::hasTable('ddflujo_venoso')) {
            Schema::create('ddflujo_venoso', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('numhistoria');
                $table->integer('numconsulta');
                $table->string('venas_e1', 2)->nullable();
                $table->string('venas_e2', 2)->nullable();
                $table->string('venas_f1', 2)->nullable();
                $table->string('venas_f2', 2)->nullable();
                $table->string('venas_a1', 2)->nullable();
                $table->string('venas_a2', 2)->nullable();
                $table->string('venas_r1', 2)->nullable();
                $table->string('venas_r2', 2)->nullable();
                $table->string('fem_comun_e1', 2)->nullable();
                $table->string('fem_comun_e2', 2)->nullable();
                $table->string('fem_comun_f1', 2)->nullable();
                $table->string('fem_comun_f2', 2)->nullable();
                $table->string('fem_comun_a1', 2)->nullable();
                $table->string('fem_comun_a2', 2)->nullable();
                $table->string('fem_comun_r1', 2)->nullable();
                $table->string('fem_comun_r2', 2)->nullable();
                $table->string('fem_superf_e1', 2)->nullable();
                $table->string('fem_superf_e2', 2)->nullable();
                $table->string('fem_superf_f1', 2)->nullable();
                $table->string('fem_superf_f2', 2)->nullable();
                $table->string('fem_superf_a1', 2)->nullable();
                $table->string('fem_superf_a2', 2)->nullable();
                $table->string('fem_superf_r1', 2)->nullable();
                $table->string('fem_superf_r2', 2)->nullable();
                $table->string('poplitea_e1', 2)->nullable();
                $table->string('poplitea_e2', 2)->nullable();
                $table->string('poplitea_f1', 2)->nullable();
                $table->string('poplitea_f2', 2)->nullable();
                $table->string('poplitea_a1', 2)->nullable();
                $table->string('poplitea_a2', 2)->nullable();
                $table->string('poplitea_r1', 2)->nullable();
                $table->string('poplitea_r2', 2)->nullable();
                $table->string('tibial_post_e1', 2)->nullable();
                $table->string('tibial_post_e2', 2)->nullable();
                $table->string('tibial_post_f1', 2)->nullable();
                $table->string('tibial_post_f2', 2)->nullable();
                $table->string('tibial_post_a1', 2)->nullable();
                $table->string('tibial_post_a2', 2)->nullable();
                $table->string('tibial_post_r1', 2)->nullable();
                $table->string('tibial_post_r2', 2)->nullable();
                $table->string('tibial_ante_e1', 2)->nullable();
                $table->string('tibial_ante_e2', 2)->nullable();
                $table->string('tibial_ante_f1', 2)->nullable();
                $table->string('tibial_ante_f2', 2)->nullable();
                $table->string('tibial_ante_a1', 2)->nullable();
                $table->string('tibial_ante_a2', 2)->nullable();
                $table->string('tibial_ante_r1', 2)->nullable();
                $table->string('tibial_ante_r2', 2)->nullable();
                $table->string('safena_e1', 2)->nullable();
                $table->string('safena_e2', 2)->nullable();
                $table->string('safena_f1', 2)->nullable();
                $table->string('safena_f2', 2)->nullable();
                $table->string('safena_a1', 2)->nullable();
                $table->string('safena_a2', 2)->nullable();
                $table->string('safena_r1', 2)->nullable();
                $table->string('safena_r2', 2)->nullable();
                $table->text('texto_reporte')->nullable();
                $table->string('indicacion', 100)->nullable();
                $table->decimal('referido_por', 15, 0)->nullable();
                $table->string('hc_text', 50)->nullable();
                $table->text('conclusiones')->nullable();
                $table->string('equipo', 60)->nullable();
                $table->string('tansductor', 60)->nullable();
                $table->string('segunda_armonica', 1)->nullable();
                $table->string('contraste', 1)->nullable();
                $table->text('modo_b')->nullable();
                $table->text('modo_color')->nullable();
                $table->string('llenado', 1)->nullable();
                $table->text('full_text')->nullable();
                $table->timestamps();
            });
        }
        // ecocardiografia   (clave del escritorio: numhistoria + numconsulta)
        if (! Schema::hasTable('ecocardiografia')) {
            Schema::create('ecocardiografia', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('numhistoria');
                $table->integer('numconsulta');
                $table->string('ha_ai', 15)->nullable();
                $table->string('ha_rao', 15)->nullable();
                $table->string('ha_gpavd', 15)->nullable();
                $table->string('ha_cavidad_vd', 15)->nullable();
                $table->string('ha_septum_iv', 15)->nullable();
                $table->string('ha_pared_post', 15)->nullable();
                $table->string('ha_ddvi', 15)->nullable();
                $table->string('ha_dsvi', 15)->nullable();
                $table->text('ha_ivsvi')->nullable();
                $table->string('ha_fa', 15)->nullable();
                $table->string('ha_fe', 15)->nullable();
                $table->text('conclusiones')->nullable();
                $table->timestamps();
            });
        }
        // ecocardiografico_in   (clave del escritorio: numhistoria + numconsulta)
        if (! Schema::hasTable('ecocardiografico_in')) {
            Schema::create('ecocardiografico_in', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('numhistoria');
                $table->integer('numconsulta');
                $table->string('diagnostico', 60)->nullable();
                $table->string('motivo_estudio', 60)->nullable();
                $table->string('tratamiento', 60)->nullable();
                $table->string('fc_maxima', 10)->nullable();
                $table->string('fc_submaxima', 10)->nullable();
                $table->text('hallasgos')->nullable();
                $table->text('super_texto')->nullable();
                $table->text('conclusion')->nullable();
                $table->string('farmacologico', 1)->nullable();
                $table->string('medicamento', 50)->nullable();
                $table->string('tipo', 1)->nullable();
                $table->timestamps();
            });
        }
        // ecocardiograma_ete   (clave del escritorio: numhistoria + numconsulta)
        if (! Schema::hasTable('ecocardiograma_ete')) {
            Schema::create('ecocardiograma_ete', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('numhistoria');
                $table->integer('numconsulta');
                $table->string('diagnostico', 60)->nullable();
                $table->string('indicacion', 60)->nullable();
                $table->string('sedacion', 60)->nullable();
                $table->decimal('medico', 15, 0)->nullable();
                $table->string('video_num', 60)->nullable();
                $table->string('transductor', 60)->nullable();
                $table->string('equipo', 60)->nullable();
                $table->text('conclusiones')->nullable();
                $table->timestamps();
            });
        }
        // prueba_esfuerzo   (clave del escritorio: numhistoria + numconsulta)
        if (! Schema::hasTable('prueba_esfuerzo')) {
            Schema::create('prueba_esfuerzo', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('numhistoria');
                $table->integer('numconsulta');
                $table->string('fre_max_alc', 30)->nullable();
                $table->string('fre_sub_max', 30)->nullable();
                $table->string('frec_alc_xmin', 30)->nullable();
                $table->string('porc_fre_max', 30)->nullable();
                $table->string('mets', 30)->nullable();
                $table->string('cons_max_oxigeno', 30)->nullable();
                $table->string('clasif_nyha', 30)->nullable();
                $table->string('clasif_astrand', 30)->nullable();
                $table->string('nivel_entrenamiento', 30)->nullable();
                $table->text('conclusiones')->nullable();
                $table->decimal('referido_por', 15, 0)->nullable();
                $table->string('diagnostico', 60)->nullable();
                $table->string('fcm', 60)->nullable();
                $table->text('observaciones')->nullable();
                $table->string('tratamiento_anterior', 60)->nullable();
                $table->timestamps();
            });
        }
        // texto_reporte   (clave del escritorio: codigo)
        if (! Schema::hasTable('texto_reporte')) {
            Schema::create('texto_reporte', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('codigo');
                $table->text('texto_venoso')->nullable();
                $table->text('hallazgos_carotidas')->nullable();
                $table->text('texto_ecocardiografico')->nullable();
                $table->timestamps();
            });
        }
        // dd_arterial_venoso_ms   (clave del escritorio: historia + consulta)
        if (! Schema::hasTable('dd_arterial_venoso_ms')) {
            Schema::create('dd_arterial_venoso_ms', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('historia');
                $table->integer('consulta');
                $table->string('indicacion', 30)->nullable();
                $table->integer('referido')->nullable();
                $table->string('equipo', 30)->nullable();
                $table->string('transductor', 30)->nullable();
                $table->text('miembro_superior_izquierdo')->nullable();
                $table->text('miembro_superior_derecho')->nullable();
                $table->text('texto')->nullable();
                $table->text('conclusion')->nullable();
                $table->timestamps();
            });
        }
        // texto_cardiaco   (clave del escritorio: clave)
        if (! Schema::hasTable('texto_cardiaco')) {
            Schema::create('texto_cardiaco', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->string('clave', 1);
                $table->text('duplex_superios');
                $table->text('peotoloco_marcapaso');
                $table->text('texto_marca');
                $table->timestamps();
            });
        }
        // orden_ingreso   (clave del escritorio: historia + consulta)
        if (! Schema::hasTable('orden_ingreso')) {
            Schema::create('orden_ingreso', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('historia');
                $table->integer('consulta');
                $table->date('fecha_operacion')->nullable();
                $table->date('fecha_reporte')->nullable();
                $table->text('texto')->nullable();
                $table->text('idx')->nullable();
                $table->string('reco_ingresar', 1)->nullable();
                $table->string('reco_depi', 1)->nullable();
                $table->string('reco_llevar', 1)->nullable();
                $table->string('reco_cedroxin', 1)->nullable();
                $table->string('reco_ejercicios', 1)->nullable();
                $table->string('reco_terapia', 1)->nullable();
                $table->string('reco_control', 1)->nullable();
                $table->string('reco_higiene', 1)->nullable();
                $table->string('reco_ropa', 1)->nullable();
                $table->string('reco_tomar', 1)->nullable();
                $table->string('reco_clexane', 1)->nullable();
                $table->string('reco_ejercicios_res', 1)->nullable();
                $table->string('reco_sangre', 1)->nullable();
                $table->string('reco_avisar', 1)->nullable();
                $table->timestamps();
            });
        }
        // implante   (clave del escritorio: historia + consulta)
        if (! Schema::hasTable('implante')) {
            Schema::create('implante', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('historia');
                $table->integer('consulta');
                $table->date('fecha_reporte')->nullable();
                $table->date('fecha_opera')->nullable();
                $table->text('diagnostico')->nullable();
                $table->text('plan_operatorio')->nullable();
                $table->text('texto')->nullable();
                $table->string('estabilizada', 1)->nullable();
                $table->string('componente_femoral', 1)->nullable();
                $table->string('componente_tibial', 10)->nullable();
                $table->string('componente_tibial_it', 10)->nullable();
                $table->string('componente_pastelar', 1)->nullable();
                $table->string('reque_metilmeticatrilato', 1)->nullable();
                $table->string('reque_metilmeticatrilato_cant', 10)->nullable();
                $table->string('reque_steridrape', 1)->nullable();
                $table->string('reque_steridrape_cant', 10)->nullable();
                $table->string('reque_coban_esteril', 1)->nullable();
                $table->string('reque_coban_esteril_cant', 10)->nullable();
                $table->string('reque_autosutura', 1)->nullable();
                $table->string('reque_autosutura_cant', 10)->nullable();
                $table->string('reque_orthopack', 1)->nullable();
                $table->string('reque_orthopack_cant', 10)->nullable();
                $table->string('sistema', 100)->nullable();
                $table->string('marca', 20)->nullable();
                $table->string('tipo', 20)->nullable();
                $table->timestamps();
            });
        }
        // implantacion_marcapasos   (clave del escritorio: historia + consulta)
        if (! Schema::hasTable('implantacion_marcapasos')) {
            Schema::create('implantacion_marcapasos', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('historia');
                $table->integer('consulta');
                $table->string('marca', 30)->nullable();
                $table->string('modelo', 30)->nullable();
                $table->string('serie', 30)->nullable();
                $table->string('serial', 30)->nullable();
                $table->string('modo', 20)->nullable();
                $table->string('frecuencia_basica', 10)->nullable();
                $table->string('frecuencia_histeresis', 10)->nullable();
                $table->string('periodo_refractario', 10)->nullable();
                $table->string('autocapture_ventricular', 10)->nullable();
                $table->string('sencibilidad_respuesta_evocada', 10)->nullable();
                $table->string('amplitud_impulso_v', 10)->nullable();
                $table->string('anchura_impulso_v', 10)->nullable();
                $table->string('sencibilidad_pr', 10)->nullable();
                $table->string('configuracion_impulso_v', 10)->nullable();
                $table->string('configuracion_deteccion_v', 10)->nullable();
                $table->string('vario', 10)->nullable();
                $table->string('tipo_cable', 10)->nullable();
                $table->string('camara_del_electrodo', 10)->nullable();
                $table->text('protocolo_implante')->nullable();
                $table->double('umbral_auricula')->nullable();
                $table->double('umbral_ventriculo')->nullable();
                $table->double('corriente_auricula')->nullable();
                $table->double('corriente_ventriculo')->nullable();
                $table->integer('resistencia_auricula')->nullable();
                $table->integer('resistencia_ventriculo')->nullable();
                $table->double('onda_pr_auricula')->nullable();
                $table->double('onda_pr_ventricula')->nullable();
                $table->double('ancho_pulso_auricula')->nullable();
                $table->double('ancho_pulso_ventricula')->nullable();
                $table->text('textazo')->nullable();
                $table->text('conclusiones')->nullable();
                $table->string('periodo_refractario_a', 30)->nullable();
                $table->string('amplitud_impulso_a', 30)->nullable();
                $table->string('anchura_impulso_a', 30)->nullable();
                $table->timestamps();
            });
        }
        // oscilometria   (clave del escritorio: historia + consulta)
        if (! Schema::hasTable('oscilometria')) {
            Schema::create('oscilometria', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('historia');
                $table->integer('consulta');
                $table->string('indicacion', 30)->nullable();
                $table->integer('referido')->nullable();
                $table->double('ind_tobillo_brazo_derecho')->nullable();
                $table->double('ind_tobillo_brazo_izquierdo')->nullable();
                $table->double('ind_muslo_brazo_derecho')->nullable();
                $table->double('ind_muslo_brazo_izquierdo')->nullable();
                $table->string('gradiente_sistolico', 1)->nullable();
                $table->string('segmentos_mp_p_der', 10)->nullable();
                $table->string('segmentos_mp_md_der', 10)->nullable();
                $table->string('segmentos_t_p_der', 10)->nullable();
                $table->string('segmentos_t_md_der', 10)->nullable();
                $table->string('segmentos_mp_p_izq', 10)->nullable();
                $table->string('segmentos_mp_md_izq', 10)->nullable();
                $table->string('segmentos_t_p_izq', 10)->nullable();
                $table->string('segmentos_t_md_izq', 10)->nullable();
                $table->string('tobillos', 30)->nullable();
                $table->string('pantorrillas', 30)->nullable();
                $table->string('muslo_distal', 30)->nullable();
                $table->string('muslo_proximal', 30)->nullable();
                $table->string('reserva_circulatoria_der', 1)->nullable();
                $table->string('reserva_circulatoria_izq', 1)->nullable();
                $table->string('compensacion_hemodinamica_der', 1)->nullable();
                $table->string('compensacion_hemodinamica_izq', 1)->nullable();
                $table->text('conclusiones')->nullable();
                $table->timestamps();
            });
        }
        // archivos_historia 
        if (! Schema::hasTable('archivos_historia')) {
            Schema::create('archivos_historia', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('nrohistoria');
                $table->integer('nroconsulta');
                $table->string('archivo', 200)->nullable();
                $table->timestamps();
            });
        }
    }

    /** No se borra nada: en desarrollo alguna de estas tablas puede existir con datos. */
    public function down(): void
    {
    }
};

