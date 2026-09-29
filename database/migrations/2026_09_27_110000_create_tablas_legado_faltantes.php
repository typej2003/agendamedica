<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tablas del legado (GinecoReport) que faltaban en el API: la carga inicial del escritorio
 * (2026-09-27) las encontró con datos del médico y no tenía dónde guardarlas (reposos, exámenes
 * prenatales, referencias, radiología, ecografías `ultra_*`, catálogos...).
 *
 * Las definiciones salen TAL CUAL del dump del legado ya migrado a MySQL
 * (`Docs/MICONSULTAGI/archivos sql/export_parte_*.sql`), no se inventaron: mismas columnas, tipos y
 * nulabilidad, más `id`, `reg_medico` y fechas como el resto de las tablas del legado.
 *
 * Con `hasTable`: el servidor de desarrollo se cargó desde ese dump y puede tener alguna ya creada.
 * Por la misma razón `down()` no las borra si existían antes (no hay forma de saberlo acá); ver la
 * nota en down().
 *
 * También agrega `pre_natal_desarrollo_fino.cantidad` (INT NULL, como en el dump), que existe en el
 * escritorio pero faltaba en el API.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('reposo_paciente')) {
            Schema::create('reposo_paciente', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('nrohistoria');
                $table->integer('nroconsulta');
                $table->string('codereposo', 1)->nullable();
                $table->date('fdesde')->nullable();
                $table->integer('numdias')->nullable();
                $table->text('obser_reposo')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('pre_natal_examenes')) {
            Schema::create('pre_natal_examenes', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('historia');
                $table->date('fecha');
                $table->string('hemoglobina', 20)->nullable();
                $table->string('hematocrito', 20)->nullable();
                $table->string('plaquetas', 20)->nullable();
                $table->string('glicemia', 20)->nullable();
                $table->string('urea', 20)->nullable();
                $table->string('creatinina', 20)->nullable();
                $table->string('vdrl', 20)->nullable();
                $table->string('hiv', 20)->nullable();
                $table->string('ac_urico', 20)->nullable();
                $table->string('toxotest', 20)->nullable();
                $table->string('taxoplasmosis_igm', 20)->nullable();
                $table->string('orina', 20)->nullable();
                $table->string('otros', 20)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('pre_natal_observaciones')) {
            Schema::create('pre_natal_observaciones', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('historia');
                $table->date('fur')->nullable();
                $table->string('menarquia', 10)->nullable();
                $table->integer('gestas')->nullable();
                $table->string('parto', 10)->nullable();
                $table->string('tipiaje', 10)->nullable();
                $table->text('observaciones')->nullable();
                $table->string('tipiaje_cony', 10)->nullable();
                $table->integer('gesta_clave')->nullable();
                $table->integer('partos')->nullable();
                $table->integer('cesarea')->nullable();
                $table->integer('abortos')->nullable();
                $table->integer('otros')->nullable();
                $table->string('final', 1)->nullable();
                $table->string('multiple', 1)->nullable();
                $table->integer('cantidad')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('referencia')) {
            Schema::create('referencia', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('nrohistoria');
                $table->integer('nroconsulta');
                $table->decimal('ceduladoctor', 15, 0);
                $table->text('referencia')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('radiologia_paciente')) {
            Schema::create('radiologia_paciente', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('nrohistoria');
                $table->integer('nroconsulta');
                $table->string('coderadio', 8);
                $table->integer('nroopcion')->nullable();
                $table->integer('orden')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('radiologia_obs')) {
            Schema::create('radiologia_obs', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('numhistoria');
                $table->integer('numconsulta');
                $table->text('observacion')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('vademecum_m')) {
            Schema::create('vademecum_m', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->string('codemedicina', 200);
                $table->string('nombregenerico', 35)->nullable();
                $table->string('nombrecomercial', 35)->nullable();
                $table->text('dosificacion')->nullable();
                $table->text('uso')->nullable();
                $table->string('presentacion', 35)->nullable();
                $table->double('concentracion')->nullable();
                $table->double('cada')->nullable();
                $table->integer('durante')->nullable();
                $table->double('pvc')->nullable();
                $table->double('pvs')->nullable();
                $table->double('dosis')->nullable();
                $table->string('sico', 1)->nullable();
                $table->string('nombrecomercial1', 40)->nullable();
                $table->string('nombrecomercial2', 40)->nullable();
                $table->string('nombrecomercial3', 40)->nullable();
                $table->text('totalre')->nullable();
                $table->string('sicome', 1)->nullable();
                $table->string('sicome1', 1)->nullable();
                $table->string('sicome2', 1)->nullable();
                $table->string('sicome3', 1)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ultra_mama')) {
            Schema::create('ultra_mama', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('historia');
                $table->integer('consulta');
                $table->date('fecha')->nullable();
                $table->integer('referido')->nullable();
                $table->string('menarquia', 20)->nullable();
                $table->string('para', 60)->nullable();
                $table->string('quirurgicos', 60)->nullable();
                $table->string('hormonas', 100)->nullable();
                $table->string('tranductor', 100)->nullable();
                $table->text('mama_derecha')->nullable();
                $table->text('axila_derecha')->nullable();
                $table->text('mama_izquierda')->nullable();
                $table->text('axila_izquierda')->nullable();
                $table->text('puro_texto')->nullable();
                $table->text('conclusion')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ultra_abdominal')) {
            Schema::create('ultra_abdominal', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('historia');
                $table->integer('consulta');
                $table->date('fecha')->nullable();
                $table->string('transductor', 30)->nullable();
                $table->integer('referido')->nullable();
                $table->text('vesicula_bilial')->nullable();
                $table->text('higado')->nullable();
                $table->text('porta_coledoco')->nullable();
                $table->text('pancreas')->nullable();
                $table->text('rinon_derecho')->nullable();
                $table->text('rinon_izquierdo')->nullable();
                $table->text('prostata')->nullable();
                $table->string('vejiga_urina', 1)->nullable();
                $table->string('vol_res_v_p_m', 1)->nullable();
                $table->string('ascitis', 1)->nullable();
                $table->string('retroperitoneo', 1)->nullable();
                $table->string('vasos_sanguineos', 1)->nullable();
                $table->text('otros')->nullable();
                $table->text('conclusiones')->nullable();
                $table->text('puro_texto')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('prena_exames_b')) {
            Schema::create('prena_exames_b', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('historia');
                $table->date('fecha');
                $table->integer('gesta_clave');
                $table->string('hemoglobina', 5)->nullable();
                $table->string('hematoc', 5)->nullable();
                $table->string('glob_blanco', 5)->nullable();
                $table->string('neut_linf', 5)->nullable();
                $table->string('vsg', 5)->nullable();
                $table->string('plaquetas', 5)->nullable();
                $table->string('glicemia_basal', 5)->nullable();
                $table->string('glicemia_post_prandial', 5)->nullable();
                $table->string('insulina_basal', 5)->nullable();
                $table->string('insulina_post_prandial', 5)->nullable();
                $table->string('urea', 5)->nullable();
                $table->string('creatinina', 5)->nullable();
                $table->string('ac_urico', 5)->nullable();
                $table->string('triglicer', 5)->nullable();
                $table->string('colesterol', 5)->nullable();
                $table->string('hdl_colest', 5)->nullable();
                $table->string('ldl_colest', 5)->nullable();
                $table->string('hiv', 1)->nullable();
                $table->string('vdrl', 1)->nullable();
                $table->text('orina')->nullable();
                $table->string('urocultivo', 1)->nullable();
                $table->text('heces')->nullable();
                $table->string('t3', 5)->nullable();
                $table->string('t4', 5)->nullable();
                $table->string('tsh', 5)->nullable();
                $table->string('fsh', 5)->nullable();
                $table->string('lh', 5)->nullable();
                $table->string('estradiol', 5)->nullable();
                $table->string('progester', 5)->nullable();
                $table->string('prolactina', 5)->nullable();
                $table->string('toxo_igm', 1)->nullable();
                $table->string('toxo_igg', 1)->nullable();
                $table->text('otros')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('radiologias')) {
            Schema::create('radiologias', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->string('coderadio', 8);
                $table->string('estudio', 45)->nullable();
                $table->string('codetipo', 10)->nullable();
                $table->text('opciones')->nullable();
                $table->string('tipo', 40)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('tipos_examenes')) {
            Schema::create('tipos_examenes', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->string('codetipo', 10);
                $table->string('tipo', 40)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('tipo_antecedente')) {
            Schema::create('tipo_antecedente', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->string('codetipo', 2);
                $table->string('descripcion', 40)->nullable();
                $table->string('tipoantecedente', 1)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('seg_emp')) {
            Schema::create('seg_emp', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->string('codesegemp', 3);
                $table->string('nombre', 150)->nullable();
                $table->string('rif', 50)->nullable();
                $table->string('direccion', 350)->nullable();
                $table->string('telef', 50)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('tipos_conceptos')) {
            Schema::create('tipos_conceptos', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->string('id_tipo_concepto', 4);
                $table->string('des_concepto', 100)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('tipos_documentos')) {
            Schema::create('tipos_documentos', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->string('tip_documento', 2);
                $table->string('des_documento', 100)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('reg_empl_frec_nomina')) {
            Schema::create('reg_empl_frec_nomina', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->string('frecuencia_nomina', 2);
                $table->string('nombre_frecuencia', 50)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('reg_empl_tipo_nomina')) {
            Schema::create('reg_empl_tipo_nomina', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->string('tipo_nomina', 2);
                $table->string('nombre_nomina', 50)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ultra_tiroides_musculo')) {
            Schema::create('ultra_tiroides_musculo', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('historia');
                $table->integer('consulta');
                $table->date('fecha')->nullable();
                $table->string('transductor', 30)->nullable();
                $table->integer('referido')->nullable();
                $table->text('texto')->nullable();
                $table->text('puro_texto')->nullable();
                $table->text('conclusiones')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('recipes_pareja')) {
            Schema::create('recipes_pareja', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('nrohistoria');
                $table->integer('nroconsulta');
                $table->string('codemedicina', 8);
                $table->text('indicaciones')->nullable();
                $table->integer('cantidad')->nullable();
                $table->string('descripcion', 200)->nullable();
                $table->integer('orden')->nullable();
                $table->string('cedula', 10)->nullable();
                $table->string('nombre', 200)->nullable();
                $table->string('procedencia', 4)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('texto_doppler')) {
            Schema::create('texto_doppler', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->string('nivel', 10);
                $table->text('texto');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ultra_testiculos')) {
            Schema::create('ultra_testiculos', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('historia');
                $table->integer('consulta');
                $table->date('fecha')->nullable();
                $table->string('equipo', 20)->nullable();
                $table->string('transductor', 20)->nullable();
                $table->double('frecuencia')->nullable();
                $table->text('motivo_estudio')->nullable();
                $table->double('td_long')->nullable();
                $table->double('td_tranv')->nullable();
                $table->double('td_post')->nullable();
                $table->text('td_parequima')->nullable();
                $table->text('td_epididimo')->nullable();
                $table->double('td_epididimo_med_1')->nullable();
                $table->double('td_epididimo_med_2')->nullable();
                $table->string('td_hidrocele', 50)->nullable();
                $table->double('td_diametro_plexo')->nullable();
                $table->double('ti_long')->nullable();
                $table->double('ti_tranv')->nullable();
                $table->double('ti_post')->nullable();
                $table->text('ti_parequima')->nullable();
                $table->text('ti_epididimo')->nullable();
                $table->double('ti_epididimo_med_1')->nullable();
                $table->double('ti_epididimo_med_2')->nullable();
                $table->string('ti_hidrocele', 50)->nullable();
                $table->double('ti_diametro_plexo')->nullable();
                $table->text('conclusion')->nullable();
                $table->text('hallazgos')->nullable();
                $table->integer('medico_1')->nullable();
                $table->integer('medico_2')->nullable();
                $table->text('doppler_td')->nullable();
                $table->text('doppler_ti')->nullable();
                $table->text('observaciones')->nullable();
                $table->text('puro_texto')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('proveedor')) {
            Schema::create('proveedor', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->string('cod_prov', 6);
                $table->string('proveedor', 150)->nullable();
                $table->string('rif', 20)->nullable();
                $table->string('direccion', 300)->nullable();
                $table->string('telefono', 20)->nullable();
                $table->string('contacto', 100)->nullable();
                $table->string('celular', 20)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('tipos_recipe')) {
            Schema::create('tipos_recipe', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->string('codetipo', 10);
                $table->string('tipo', 40)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('ultra_prostatico')) {
            Schema::create('ultra_prostatico', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('historia');
                $table->integer('consulta');
                $table->date('fecha')->nullable();
                $table->double('psa_total')->nullable();
                $table->double('psa_libre')->nullable();
                $table->double('psa_relacion')->nullable();
                $table->text('tacto_rectal')->nullable();
                $table->text('tex_basico')->nullable();
                $table->string('anestesia', 10)->nullable();
                $table->double('diametro_long')->nullable();
                $table->double('diametro_trans')->nullable();
                $table->double('diametro_anterop')->nullable();
                $table->double('volumen')->nullable();
                $table->double('densidad')->nullable();
                $table->string('capsula', 10)->nullable();
                $table->string('nodulos', 10)->nullable();
                $table->string('nodulos_ubicacion', 10)->nullable();
                $table->string('nodulos_caracteristicas', 300)->nullable();
                $table->double('protocolo_biopsia_cilindros_de')->nullable();
                $table->double('protocolo_biopsia_cilindros_iz')->nullable();
                $table->text('conclusion')->nullable();
                $table->integer('medico_1')->nullable();
                $table->integer('medico_2')->nullable();
                $table->string('equipo', 20)->nullable();
                $table->string('trasductor', 20)->nullable();
                $table->double('frecuencia')->nullable();
                $table->string('diametro_ld', 10)->nullable();
                $table->string('diametro_li', 10)->nullable();
                $table->double('diametro_long_ld')->nullable();
                $table->double('diametro_long_li')->nullable();
                $table->double('diametro_trans_ld')->nullable();
                $table->double('diametro_trans_li')->nullable();
                $table->double('diametro_anterop_ld')->nullable();
                $table->double('diametro_anterop_li')->nullable();
                $table->text('vesiculas_s')->nullable();
                $table->text('puro_texto')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('recipe2')) {
            Schema::create('recipe2', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('nrohistoria');
                $table->integer('nroconsulta');
                $table->string('codemedicina', 8);
                $table->text('indicaciones')->nullable();
                $table->integer('cantidad')->nullable();
                $table->string('descripcion', 200)->nullable();
                $table->integer('orden')->nullable();
                $table->date('fecha')->nullable();
                $table->integer('recipe')->nullable();
                $table->string('comple', 1)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('presupuesto_operatorio')) {
            Schema::create('presupuesto_operatorio', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('documento');
                $table->integer('historia')->nullable();
                $table->string('diagnostico', 300)->nullable();
                $table->string('intervencion', 100)->nullable();
                $table->integer('ayudantes')->nullable();
                $table->integer('dias_hospi')->nullable();
                $table->string('arco_c', 1)->nullable();
                $table->string('astroscopio', 1)->nullable();
                $table->string('sangre_qx_tipo_1', 40)->nullable();
                $table->string('sangre_qx_tipo_2', 10)->nullable();
                $table->double('sangre_qx_tipo_1_cantidad')->nullable();
                $table->double('sangre_qx_tipo_2_cantidad')->nullable();
                $table->double('material_sintesis')->nullable();
                $table->double('instrumental_traumatologico')->nullable();
                $table->double('honorarios')->nullable();
                $table->text('observaciones')->nullable();
                $table->date('fecha')->nullable();
                $table->string('estado', 1)->nullable();
                $table->string('clinica', 3)->nullable();
                $table->string('procedencia', 3)->nullable();
                $table->integer('horas_quirofano')->nullable();
                $table->string('rx_torax', 1)->nullable();
                $table->string('rx_postoperatoria', 1)->nullable();
                $table->string('fluoroscopio', 1)->nullable();
                $table->string('eval_preoperatoria', 1)->nullable();
                $table->string('otros_estudios_de_imagenes', 100)->nullable();
                $table->string('interconsultas', 100)->nullable();
                $table->double('h_1_ayudante')->nullable();
                $table->double('h_2_ayudante')->nullable();
                $table->double('h_anestesiologo')->nullable();
                $table->double('h_tratante')->nullable();
                $table->double('h_artroscopio')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('registro_empleados')) {
            Schema::create('registro_empleados', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->double('nro_empleado');
                $table->date('fecha_creacion')->nullable();
                $table->string('status', 1)->nullable();
                $table->string('cedula_empleado', 20)->nullable();
                $table->string('nombre_empleado', 100)->nullable();
                $table->string('tipo_nomina', 2)->nullable();
                $table->string('frecuencia_nomina', 2)->nullable();
                $table->double('monto_s1')->nullable();
                $table->double('monto_s2')->nullable();
                $table->double('monto_s3')->nullable();
                $table->double('monto_s4')->nullable();
                $table->double('monto_total')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('registro_empleados_eje')) {
            Schema::create('registro_empleados_eje', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->double('nro_nomina');
                $table->string('descripcion', 100)->nullable();
                $table->date('fecha_aplicacion')->nullable();
                $table->string('status', 1)->nullable();
                $table->string('tipo_nomina', 2)->nullable();
                $table->string('frecuencia_nomina', 2)->nullable();
                $table->double('monto_total')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('registro_empleados_eje_detalle')) {
            Schema::create('registro_empleados_eje_detalle', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->double('nro_nomina');
                $table->double('nro_empleado');
                $table->double('nro_cxp')->nullable();
                $table->double('monto_empleado')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('representante')) {
            Schema::create('representante', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('numhistoria');
                $table->string('nombre', 40)->nullable();
                $table->string('codeparentesco', 1)->nullable();
                $table->string('direccion', 60)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('presupuesto_planti')) {
            Schema::create('presupuesto_planti', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('consecutivo');
                $table->string('tipo_precio', 1)->nullable();
                $table->double('total_costo')->nullable();
                $table->double('total_final')->nullable();
                $table->text('notas')->nullable();
                $table->string('tipo_doc', 3)->nullable();
                $table->string('nom_presupuesto', 100)->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('sms_enviados')) {
            Schema::create('sms_enviados', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->double('conta');
                $table->string('usuario', 10)->nullable();
                $table->string('medico', 60)->nullable();
                $table->string('proveedor', 1)->nullable();
                $table->string('numero', 11)->nullable();
                $table->string('mensaje', 150)->nullable();
                $table->date('fecha')->nullable();
                $table->string('tipo', 1)->nullable();
                $table->integer('historia')->nullable();
                $table->integer('consulta')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('sms_envio_pac')) {
            Schema::create('sms_envio_pac', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('nrohistoria');
                $table->integer('nroconsulta');
                $table->string('numero_cel', 14)->nullable();
                $table->string('texto_sms', 160)->nullable();
                $table->integer('orden')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('sms_compra')) {
            Schema::create('sms_compra', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('conse_compra');
                $table->date('fecha_compra')->nullable();
                $table->double('monto_compra')->nullable();
                $table->integer('cantidad_compra')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('pre_natal_desarrollo_fino') && ! Schema::hasColumn('pre_natal_desarrollo_fino', 'cantidad')) {
            Schema::table('pre_natal_desarrollo_fino', function (Blueprint $table) {
                $table->integer('cantidad')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Solo para entornos locales: en un servidor cargado desde el dump estas tablas pueden
        // haber existido antes de esta migración.
        if (Schema::hasColumn('pre_natal_desarrollo_fino', 'cantidad')) {
            Schema::table('pre_natal_desarrollo_fino', function (Blueprint $table) {
                $table->dropColumn('cantidad');
            });
        }
        Schema::dropIfExists('sms_compra');
        Schema::dropIfExists('sms_envio_pac');
        Schema::dropIfExists('sms_enviados');
        Schema::dropIfExists('presupuesto_planti');
        Schema::dropIfExists('representante');
        Schema::dropIfExists('registro_empleados_eje_detalle');
        Schema::dropIfExists('registro_empleados_eje');
        Schema::dropIfExists('registro_empleados');
        Schema::dropIfExists('presupuesto_operatorio');
        Schema::dropIfExists('recipe2');
        Schema::dropIfExists('ultra_prostatico');
        Schema::dropIfExists('tipos_recipe');
        Schema::dropIfExists('proveedor');
        Schema::dropIfExists('ultra_testiculos');
        Schema::dropIfExists('texto_doppler');
        Schema::dropIfExists('recipes_pareja');
        Schema::dropIfExists('ultra_tiroides_musculo');
        Schema::dropIfExists('reg_empl_tipo_nomina');
        Schema::dropIfExists('reg_empl_frec_nomina');
        Schema::dropIfExists('tipos_documentos');
        Schema::dropIfExists('tipos_conceptos');
        Schema::dropIfExists('seg_emp');
        Schema::dropIfExists('tipo_antecedente');
        Schema::dropIfExists('tipos_examenes');
        Schema::dropIfExists('radiologias');
        Schema::dropIfExists('prena_exames_b');
        Schema::dropIfExists('ultra_abdominal');
        Schema::dropIfExists('ultra_mama');
        Schema::dropIfExists('vademecum_m');
        Schema::dropIfExists('radiologia_obs');
        Schema::dropIfExists('radiologia_paciente');
        Schema::dropIfExists('referencia');
        Schema::dropIfExists('pre_natal_observaciones');
        Schema::dropIfExists('pre_natal_examenes');
        Schema::dropIfExists('reposo_paciente');
    }
};
