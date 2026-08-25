<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // 1. examen_pareja
        Schema::create('examen_pareja', function (Blueprint $table) {
            $table->id();
            $table->integer('nrohistoria');
            $table->integer('nroconsulta');
            $table->string('codeexamen', 8);
            $table->integer('orden')->nullable();
            $table->string('cedula', 10)->nullable();
            $table->string('nombre', 200)->nullable();
            $table->string('procedencia', 4)->nullable();
        });

        // 2. examenes
        Schema::create('examenes', function (Blueprint $table) {
            $table->id();
            $table->string('codeexamen', 8);
            $table->string('examen', 45)->nullable();
            $table->string('codetipo', 10)->nullable();
        });

        // 3. EXCLUDEOBJECT
        Schema::create('EXCLUDEOBJECT', function (Blueprint $table) {
            $table->id();
            $table->string('name', 128);
            $table->string('type', 1);
        });

        // 4. factura_cliente
        Schema::create('factura_cliente', function (Blueprint $table) {
            $table->id();
            $table->integer('numfactura');
            $table->string('cedu_rif', 15)->nullable();
            $table->date('fecha_factura')->nullable();
            $table->date('fecha_vence_fact')->nullable();
            $table->date('fecha_cance_fact')->nullable();
            $table->string('cliente', 2)->nullable();
            $table->string('status_factura', 1)->nullable();
            $table->string('tipo_factura', 1)->nullable();
            $table->string('paciente', 10)->nullable();
            $table->string('nom_paciente', 60)->nullable();
            $table->string('tipo_precio', 1)->nullable();
            $table->string('nro_orden', 10)->nullable();
            $table->double('total_costo')->nullable();
            $table->double('total_neto')->nullable();
            $table->double('total_bruto')->nullable();
            $table->double('total_final')->nullable();
            $table->double('total_descuento')->nullable();
            $table->double('descuento_linea')->nullable();
            $table->text('notas')->nullable();
            $table->time('hora_documento')->nullable();
            $table->double('monto_pac')->nullable();
            $table->double('monto_emp')->nullable();
            $table->integer('numero_consulta')->nullable();
            $table->integer('numero_historia')->nullable();
            $table->string('tipo_doc', 3);
            $table->double('nro_cxp')->nullable();
            $table->integer('medico')->nullable();
        });

        // 5. facturas_compras
        Schema::create('facturas_compras', function (Blueprint $table) {
            $table->id();
            $table->double('id_factura_compra');
            $table->string('cod_prov', 6)->nullable();
            $table->string('id_tipo_concepto', 4)->nullable();
            $table->date('fecha')->nullable();
            $table->string('estado', 1)->nullable();
            $table->string('nro_seniat', 25)->nullable();
            $table->string('nro_factura', 25)->nullable();
            $table->double('mto_neto')->nullable();
            $table->double('mto_porc_iva')->nullable();
            $table->double('mto_iva')->nullable();
            $table->double('mto_porc_ret_iva')->nullable();
            $table->double('mto_ret_iva')->nullable();
            $table->double('mto_porc_ret_isrl')->nullable();
            $table->double('mto_ret_isrl')->nullable();
            $table->double('mto_total_general')->nullable();
            $table->double('nro_cxp')->nullable();
            $table->double('mto_total')->nullable();
        });

        // 6. facturas_compras_detalle
        Schema::create('facturas_compras_detalle', function (Blueprint $table) {
            $table->id();
            $table->double('id_registro');
            $table->string('id_fac_prov', 6)->nullable();
            $table->string('des_concepto', 100)->nullable();
            $table->double('cantidad')->nullable();
            $table->double('monto')->nullable();
            $table->double('monto_total')->nullable();
            $table->double('id_factura_compra')->nullable();
        });

        // 7. formato_print
        Schema::create('formato_print', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 4);
            $table->string('titulo', 200)->nullable();
            $table->text('descripcion')->nullable();
            $table->string('tipo', 10)->nullable();
        });

        // 8. his_con_pre_factura
        Schema::create('his_con_pre_factura', function (Blueprint $table) {
            $table->id();
            $table->double('id_registro');
            $table->integer('numhistoria')->nullable();
            $table->integer('nroconsulta')->nullable();
            $table->string('codigo', 8)->nullable();
            $table->string('descripcion', 45)->nullable();
            $table->double('cantidad')->nullable();
            $table->double('monto')->nullable();
        });

        // 9. hospitalizacion
        Schema::create('hospitalizacion', function (Blueprint $table) {
            $table->id();
            $table->integer('nrohistoria');
            $table->integer('nroconsulta');
            $table->text('diagprev')->nullable();
            $table->text('indiprev')->nullable();
            $table->text('dieta')->nullable();
            $table->string('habitacion', 1)->nullable();
            $table->string('hospital', 60)->nullable();
        });

        // 10. imagen_consulta
        Schema::create('imagen_consulta', function (Blueprint $table) {
            $table->id();
            $table->integer('nrohistoria');
            $table->integer('nroconsulta');
            $table->text('observacion')->nullable();
            $table->string('imagen', 256);
            $table->integer('orden')->nullable();
        });

        // 11. imagen_pacientes
        Schema::create('imagen_pacientes', function (Blueprint $table) {
            $table->id();
            $table->integer('nrohistoria');
            $table->string('imagen', 256);
            $table->string('imagen2', 256)->nullable();
        });

        // 12. imagen_pacientes_2
        Schema::create('imagen_pacientes_2', function (Blueprint $table) {
            $table->id();
            $table->integer('nrohistoria');
            $table->string('imagen', 256)->nullable();
            $table->string('imagen2', 256)->nullable();
        });

        // 13. imagenes
        Schema::create('imagenes', function (Blueprint $table) {
            $table->id();
            $table->string('descripcion', 30);
            $table->string('imagen', 256);
        });

        // 14. informe
        Schema::create('informe', function (Blueprint $table) {
            $table->id();
            $table->integer('nrohistoria');
            $table->integer('nroconsulta');
            $table->string('para', 50)->nullable();
            $table->text('descripcion');
            $table->date('fe_cha')->nullable();
        });

        // 15. intenven_servi
        Schema::create('intenven_servi', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 3);
            $table->string('nombre', 300)->nullable();
            $table->double('precio_principal')->nullable();
            $table->double('precio_auxiliar')->nullable();
        });

        // 16. jdbc_function_escapes
        Schema::create('jdbc_function_escapes', function (Blueprint $table) {
            $table->id();
            $table->string('escape_name', 40);
            $table->string('map_string', 40)->nullable();
        });

        // 17. listado
        Schema::create('listado', function (Blueprint $table) {
            $table->id();
            $table->string('tipo', 5);
            $table->string('descripcion_tipo', 30);
            $table->string('segundo', 5);
        });

        // 18. migrate_remote_fks_list
        Schema::create('migrate_remote_fks_list', function (Blueprint $table) {
            $table->id();
            $table->integer('fk_id');
            $table->string('pk_database', 255)->nullable();
            $table->string('pk_owner', 255)->nullable();
            $table->string('pk_table', 255);
            $table->string('pk_column', 255);
            $table->string('fk_database', 255)->nullable();
            $table->string('fk_owner', 255)->nullable();
            $table->string('fk_table', 255);
            $table->string('fk_column', 255);
            $table->integer('key_seq');
            $table->string('fk_name', 255)->nullable();
            $table->string('pk_name', 255)->nullable();
            $table->boolean('created');
        });

        // 19. migrate_remote_table_list
        Schema::create('migrate_remote_table_list', function (Blueprint $table) {
            $table->id();
            $table->integer('table_id');
            $table->string('server_name', 255);
            $table->string('database_name', 255)->nullable();
            $table->string('owner_name', 255)->nullable();
            $table->string('table_name', 255);
            $table->string('table_type', 255)->nullable();
            $table->boolean('created_proxy');
            $table->boolean('created_real');
            $table->boolean('dropped');
            $table->boolean('data_migrated');
        });

        // 20. migrate_sql_defn
        Schema::create('migrate_sql_defn', function (Blueprint $table) {
            $table->id();
            $table->text('unld_str')->nullable();
            $table->text('et_table_id')->nullable();
        });

        // 21. ml_connection_script
        Schema::create('ml_connection_script', function (Blueprint $table) {
            $table->id();
            $table->integer('version_id');
            $table->string('event', 128);
            $table->integer('script_id');
        });

        // 22. ml_script
        Schema::create('ml_script', function (Blueprint $table) {
            $table->id();
            $table->integer('script_id');
            $table->text('script');
            $table->string('script_language', 128);
        });

        // 23. ml_script_version
        Schema::create('ml_script_version', function (Blueprint $table) {
            $table->id();
            $table->integer('version_id');
            $table->string('name', 128);
            $table->text('description')->nullable();
        });

        // 24. ml_scripts_modified
        Schema::create('ml_scripts_modified', function (Blueprint $table) {
            $table->id();
            $table->dateTime('last_modified');
        });

        // 25. ml_subscription
        Schema::create('ml_subscription', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id');
            $table->string('publication_name', 128);
            $table->decimal('progress', 20, 0);
        });

        // 26. ml_table
        Schema::create('ml_table', function (Blueprint $table) {
            $table->id();
            $table->integer('table_id');
            $table->string('name', 128);
        });

        // 27. ml_table_script
        Schema::create('ml_table_script', function (Blueprint $table) {
            $table->id();
            $table->integer('version_id');
            $table->integer('table_id');
            $table->string('event', 128);
            $table->integer('script_id');
        });

        // 28. ml_user
        Schema::create('ml_user', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id');
            $table->string('name', 128);
            $table->integer('commit_state');
            $table->decimal('progress', 20, 0);
            $table->binary('hashed_password')->nullable();
        });

        // 29. motivo_cita
        Schema::create('motivo_cita', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 10);
            $table->string('tipo_atencion', 100)->nullable();
        });

        // 30. motivo_consulta_paciente
        Schema::create('motivo_consulta_paciente', function (Blueprint $table) {
            $table->id();
            $table->string('codemotivo', 4);
            $table->integer('nrohistoria');
            $table->integer('nroconsulta');
            $table->string('descripcion', 50)->nullable();
            $table->string('detalle', 300)->nullable();
        });

        // 31. motivo_factura
        Schema::create('motivo_factura', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 8);
            $table->string('descripcion', 45)->nullable();
            $table->double('monto');
            $table->double('monto_seg')->nullable();
            $table->string('tipo', 1)->nullable();
        });

        // 32. motivo_factura_prov
        Schema::create('motivo_factura_prov', function (Blueprint $table) {
            $table->id();
            $table->string('id_fac_prov', 6);
            $table->string('des_concepto', 100)->nullable();
            $table->double('monto')->nullable();
        });

        // 33. motivos_consulta
        Schema::create('motivos_consulta', function (Blueprint $table) {
            $table->id();
            $table->string('codemotivo', 4);
            $table->string('descripcion', 40);
        });

        // 34. operadores
        Schema::create('operadores', function (Blueprint $table) {
            $table->id();
            $table->string('primera', 15);
            $table->string('segunda', 10);
            $table->string('nonbre', 50)->nullable();
            $table->string('nivel', 1)->nullable();
        });

        // 35. paciente_no_regi
        Schema::create('paciente_no_regi', function (Blueprint $table) {
            $table->id();
            $table->decimal('registro', 5, 0);
            $table->string('apellidos', 100)->nullable();
            $table->string('nombres', 100)->nullable();
            $table->string('celular', 30)->nullable();
            $table->string('motivo', 10)->nullable();
            $table->date('fecha')->nullable();
            $table->integer('medico')->nullable();
            $table->string('registrado', 1)->nullable();
            $table->time('hora')->nullable();
        });

        // 36. pacientes
        Schema::create('pacientes', function (Blueprint $table) {
            $table->id();
            $table->string('nac', 1)->nullable();
            $table->string('cedula', 10)->nullable();
            $table->string('apellidos', 25)->nullable();
            $table->string('nombres', 25)->nullable();
            $table->string('sexo', 1)->nullable();
            $table->date('fnacimiento')->nullable();
            $table->string('lnacimiento', 100)->nullable();
            $table->string('codeestado', 1)->nullable();
            $table->string('direccion', 200)->nullable();
            $table->string('telefono', 20)->nullable();
            $table->date('fingreso')->nullable();
            $table->string('escolaridad', 100)->nullable();
            $table->string('ocupacion', 100)->nullable();
            $table->string('codesegemp', 3)->nullable();
            $table->integer('numhistoria');
            $table->string('foto_pac', 300)->nullable();
            $table->string('profesion', 50)->nullable();
            $table->string('email', 100)->nullable();
            $table->string('dependencia', 30)->nullable();
            $table->integer('medico')->nullable();
            $table->string('sms', 1)->nullable();
        });

        // 37. pago_quiru
        Schema::create('pago_quiru', function (Blueprint $table) {
            $table->id();
            $table->integer('cod_pagos');
            $table->integer('consecuti');
            $table->date('fecha')->nullable();
            $table->double('monto_total')->nullable();
            $table->double('abono')->nullable();
            $table->double('resta')->nullable();
            $table->string('pago', 1)->nullable();
            $table->time('hora_pago')->nullable();
        });

        // 38. pbcatcol
        Schema::create('pbcatcol', function (Blueprint $table) {
            $table->id();
            $table->string('pbc_tnam', 129);
            $table->integer('pbc_tid')->nullable();
            $table->string('pbc_ownr', 129);
            $table->string('pbc_cnam', 129);
            $table->smallInteger('pbc_cid')->nullable();
            $table->string('pbc_labl', 254)->nullable();
            $table->smallInteger('pbc_lpos')->nullable();
            $table->string('pbc_hdr', 254)->nullable();
            $table->smallInteger('pbc_hpos')->nullable();
            $table->smallInteger('pbc_jtfy')->nullable();
            $table->string('pbc_mask', 31)->nullable();
            $table->smallInteger('pbc_case')->nullable();
            $table->smallInteger('pbc_hght')->nullable();
            $table->smallInteger('pbc_wdth')->nullable();
            $table->string('pbc_ptrn', 31)->nullable();
            $table->string('pbc_bmap', 1)->nullable();
            $table->string('pbc_init', 254)->nullable();
            $table->string('pbc_cmnt', 254)->nullable();
            $table->string('pbc_edit', 31)->nullable();
            $table->string('pbc_tag', 254)->nullable();
        });

        // 39. pbcatedt
        Schema::create('pbcatedt', function (Blueprint $table) {
            $table->id();
            $table->string('pbe_name', 30);
            $table->string('pbe_edit', 254)->nullable();
            $table->smallInteger('pbe_type')->nullable();
            $table->integer('pbe_cntr')->nullable();
            $table->smallInteger('pbe_seqn');
            $table->integer('pbe_flag')->nullable();
            $table->string('pbe_work', 32)->nullable();
        });

        // 40. pbcatfmt
        Schema::create('pbcatfmt', function (Blueprint $table) {
            $table->id();
            $table->string('pbf_name', 30);
            $table->string('pbf_frmt', 254)->nullable();
            $table->smallInteger('pbf_type')->nullable();
            $table->integer('pbf_cntr')->nullable();
        });

        // 41. pbcattbl
        Schema::create('pbcattbl', function (Blueprint $table) {
            $table->id();
            $table->string('pbt_tnam', 129);
            $table->integer('pbt_tid')->nullable();
            $table->string('pbt_ownr', 129);
            $table->smallInteger('pbd_fhgt')->nullable();
            $table->smallInteger('pbd_fwgt')->nullable();
            $table->string('pbd_fitl', 1)->nullable();
            $table->string('pbd_funl', 1)->nullable();
            $table->smallInteger('pbd_fchr')->nullable();
            $table->smallInteger('pbd_fptc')->nullable();
            $table->string('pbd_ffce', 18)->nullable();
            $table->smallInteger('pbh_fhgt')->nullable();
            $table->smallInteger('pbh_fwgt')->nullable();
            $table->string('pbh_fitl', 1)->nullable();
            $table->string('pbh_funl', 1)->nullable();
            $table->smallInteger('pbh_fchr')->nullable();
            $table->smallInteger('pbh_fptc')->nullable();
            $table->string('pbh_ffce', 18)->nullable();
            $table->smallInteger('pbl_fhgt')->nullable();
            $table->smallInteger('pbl_fwgt')->nullable();
            $table->string('pbl_fitl', 1)->nullable();
            $table->string('pbl_funl', 1)->nullable();
            $table->smallInteger('pbl_fchr')->nullable();
            $table->smallInteger('pbl_fptc')->nullable();
            $table->string('pbl_ffce', 18)->nullable();
            $table->string('pbt_cmnt', 254)->nullable();
        });

        // 42. pbcatvld
        Schema::create('pbcatvld', function (Blueprint $table) {
            $table->id();
            $table->string('pbv_name', 30);
            $table->string('pbv_vald', 254)->nullable();
            $table->smallInteger('pbv_type')->nullable();
            $table->integer('pbv_cntr')->nullable();
            $table->string('pbv_msg', 254)->nullable();
        });

        // 43. pre_natal_desarrollo
        Schema::create('pre_natal_desarrollo', function (Blueprint $table) {
            $table->id();
            $table->integer('historia');
            $table->date('fecha');
            $table->string('eg_fur', 20)->nullable();
            $table->string('eg_eco', 20)->nullable();
            $table->string('peso', 20)->nullable();
            $table->string('ta', 20)->nullable();
            $table->string('au', 20)->nullable();
            $table->string('mf', 20)->nullable();
            $table->string('presentacion', 20)->nullable();
            $table->string('edemas', 20)->nullable();
            $table->string('dbp', 20)->nullable();
            $table->string('lf', 20)->nullable();
            $table->string('ac_cardiaca', 20)->nullable();
            $table->string('la', 20)->nullable();
            $table->string('sg_cri', 20)->nullable();
            $table->string('plac_grado', 20)->nullable();
        });

        // 44. pre_natal_desarrollo_fino
        Schema::create('pre_natal_desarrollo_fino', function (Blueprint $table) {
            $table->id();
            $table->integer('historia');
            $table->date('fecha');
            $table->string('eg_fur', 20)->nullable();
            $table->string('eg_eco', 20)->nullable();
            $table->string('peso', 5)->nullable();
            $table->string('ta', 7)->nullable();
            $table->string('au', 5)->nullable();
            $table->string('mf', 20)->nullable();
            $table->string('presentacion', 20)->nullable();
            $table->string('edemas', 20)->nullable();
            $table->string('dbp', 5)->nullable();
            $table->string('lf', 20)->nullable();
            $table->string('ac_cardiaca', 20)->nullable();
            $table->string('la', 20)->nullable();
            $table->string('sg_cri', 5)->nullable();
            $table->string('plac_grado', 20)->nullable();
            $table->integer('gesta_clave');
            $table->integer('consulta')->nullable();
            $table->string('femur', 5)->nullable();
            $table->string('lcc', 5)->nullable();
            $table->string('dbpf', 5)->nullable();
            $table->string('varice', 5)->nullable();
            $table->string('foco_f', 5)->nullable();
            $table->string('peso_fetal', 5)->nullable();
            $table->string('multiple', 1)->nullable();
            $table->integer('cantidad')->nullable();
        });

        // 45. pre_natal_examenes
        Schema::create('pre_natal_examenes', function (Blueprint $table) {
            $table->id();
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
        });

        // 46. pre_natal_observaciones
        Schema::create('pre_natal_observaciones', function (Blueprint $table) {
            $table->id();
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
        });

        // 47. prena_exames_b
        Schema::create('prena_exames_b', function (Blueprint $table) {
            $table->id();
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
        });

        // 48. presupuesto_operatorio
        Schema::create('presupuesto_operatorio', function (Blueprint $table) {
            $table->id();
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
        });

        // 49. presupuesto_planti
        Schema::create('presupuesto_planti', function (Blueprint $table) {
            $table->id();
            $table->integer('consecutivo');
            $table->string('tipo_precio', 1)->nullable();
            $table->double('total_costo')->nullable();
            $table->double('total_final')->nullable();
            $table->text('notas')->nullable();
            $table->string('tipo_doc', 3)->nullable();
            $table->string('nom_presupuesto', 100)->nullable();
        });

        // 50. proveedor
        Schema::create('proveedor', function (Blueprint $table) {
            $table->id();
            $table->string('cod_prov', 6);
            $table->string('proveedor', 150)->nullable();
            $table->string('rif', 20)->nullable();
            $table->string('direccion', 300)->nullable();
            $table->string('telefono', 20)->nullable();
            $table->string('contacto', 100)->nullable();
            $table->string('celular', 20)->nullable();
        });

        // 51. radiologia_obs
        Schema::create('radiologia_obs', function (Blueprint $table) {
            $table->id();
            $table->integer('numhistoria');
            $table->integer('numconsulta');
            $table->text('observacion')->nullable();
        });

        // 52. radiologia_paciente
        Schema::create('radiologia_paciente', function (Blueprint $table) {
            $table->id();
            $table->integer('nrohistoria');
            $table->integer('nroconsulta');
            $table->string('coderadio', 8);
            $table->integer('nroopcion')->nullable();
            $table->integer('orden')->nullable();
        });

        // 53. radiologias
        Schema::create('radiologias', function (Blueprint $table) {
            $table->id();
            $table->string('coderadio', 8);
            $table->string('estudio', 45)->nullable();
            $table->string('codetipo', 10)->nullable();
            $table->text('opciones')->nullable();
            $table->string('tipo', 40)->nullable();
        });

        // 54. recipe_detalle
        Schema::create('recipe_detalle', function (Blueprint $table) {
            $table->id();
            $table->integer('nrohistoria');
            $table->integer('nroconsulta');
            $table->integer('recipe');
            $table->date('fe_emision')->nullable();
            $table->integer('fe_vence')->nullable();
            $table->text('nota')->nullable();
        });

        // 55. recipe_grupo
        Schema::create('recipe_grupo', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 4);
            $table->string('tratamiento', 100)->nullable();
        });

        // 56. recipe_grupo_detalle
        Schema::create('recipe_grupo_detalle', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 4);
            $table->string('codemedicina', 8);
            $table->string('descripcion', 100)->nullable();
            $table->text('indicaciones')->nullable();
            $table->integer('cantidad')->nullable();
            $table->integer('orden')->nullable();
            $table->string('sico', 1)->nullable();
            $table->string('nombrecomercial1', 40)->nullable();
            $table->string('nombrecomercial2', 40)->nullable();
            $table->string('nombrecomercial3', 40)->nullable();
            $table->text('totalre')->nullable();
            $table->string('sicome', 1)->nullable();
            $table->string('sicome1', 1)->nullable();
            $table->string('sicome2', 1)->nullable();
            $table->string('sicome3', 1)->nullable();
        });

        // 57. recipe2
        Schema::create('recipe2', function (Blueprint $table) {
            $table->id();
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
        });

        // 58. recipes
        Schema::create('recipes', function (Blueprint $table) {
            $table->id();
            $table->integer('nrohistoria');
            $table->integer('nroconsulta');
            $table->string('codemedicina', 8);
            $table->text('indicaciones')->nullable();
            $table->integer('cantidad')->nullable();
            $table->integer('orden')->nullable();
            $table->string('descripcion', 200)->nullable();
            $table->date('fecha')->nullable();
            $table->integer('recipe')->nullable();
            $table->string('comple', 1)->nullable();
        });

        // 59. recipes_pareja
        Schema::create('recipes_pareja', function (Blueprint $table) {
            $table->id();
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
        });

        // 60. referencia
        Schema::create('referencia', function (Blueprint $table) {
            $table->id();
            $table->integer('nrohistoria');
            $table->integer('nroconsulta');
            $table->decimal('ceduladoctor', 15, 0);
            $table->text('referencia')->nullable();
        });

        // 61. reg_empl_frec_nomina
        Schema::create('reg_empl_frec_nomina', function (Blueprint $table) {
            $table->id();
            $table->string('frecuencia_nomina', 2);
            $table->string('nombre_frecuencia', 50)->nullable();
        });

        // 62. reg_empl_tipo_nomina
        Schema::create('reg_empl_tipo_nomina', function (Blueprint $table) {
            $table->id();
            $table->string('tipo_nomina', 2);
            $table->string('nombre_nomina', 50)->nullable();
        });

        // 63. registro_empleados
        Schema::create('registro_empleados', function (Blueprint $table) {
            $table->id();
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
        });

        // 64. registro_empleados_eje
        Schema::create('registro_empleados_eje', function (Blueprint $table) {
            $table->id();
            $table->double('nro_nomina');
            $table->string('descripcion', 100)->nullable();
            $table->date('fecha_aplicacion')->nullable();
            $table->string('status', 1)->nullable();
            $table->string('tipo_nomina', 2)->nullable();
            $table->string('frecuencia_nomina', 2)->nullable();
            $table->double('monto_total')->nullable();
        });

        // 65. registro_empleados_eje_detalle
        Schema::create('registro_empleados_eje_detalle', function (Blueprint $table) {
            $table->id();
            $table->double('nro_nomina');
            $table->double('nro_empleado');
            $table->double('nro_cxp')->nullable();
            $table->double('monto_empleado')->nullable();
        });

        // 66. registro_operaciones
        Schema::create('registro_operaciones', function (Blueprint $table) {
            $table->id();
            $table->double('registro');
            $table->date('dia_registro')->nullable();
            $table->time('hora_registro')->nullable();
            $table->string('operador', 10)->nullable();
            $table->string('status', 10)->nullable();
            $table->text('operacion')->nullable();
            $table->text('descricion')->nullable();
            $table->integer('historia')->nullable();
            $table->integer('consulta')->nullable();
            $table->string('medico', 50)->nullable();
            $table->string('paciente', 70)->nullable();
        });

        // 67. reposo_paciente
        Schema::create('reposo_paciente', function (Blueprint $table) {
            $table->id();
            $table->integer('nrohistoria');
            $table->integer('nroconsulta');
            $table->string('codereposo', 1)->nullable();
            $table->date('fdesde')->nullable();
            $table->integer('numdias')->nullable();
            $table->text('obser_reposo')->nullable();
        });

        // 68. representante
        Schema::create('representante', function (Blueprint $table) {
            $table->id();
            $table->integer('numhistoria');
            $table->string('nombre', 40)->nullable();
            $table->string('codeparentesco', 1)->nullable();
            $table->string('direccion', 60)->nullable();
        });

        // 69. RowGenerator
        Schema::create('RowGenerator', function (Blueprint $table) {
            $table->id();
            $table->smallInteger('row_num');
        });

        // 70. rs_lastcommit
        Schema::create('rs_lastcommit', function (Blueprint $table) {
            $table->id();
            $table->integer('origin');
            $table->binary('origin_qid')->nullable();
            $table->binary('secondary_qid')->nullable();
            $table->dateTime('origin_time')->nullable();
            $table->dateTime('commit_time')->nullable();
        });

        // 71. rs_threads
        Schema::create('rs_threads', function (Blueprint $table) {
            $table->id();
            $table->integer('seq')->nullable();
        });

        // 72. seg_emp
        Schema::create('seg_emp', function (Blueprint $table) {
            $table->id();
            $table->string('codesegemp', 3);
            $table->string('nombre', 150)->nullable();
            $table->string('rif', 50)->nullable();
            $table->string('direccion', 350)->nullable();
            $table->string('telef', 50)->nullable();
        });

        // 73. sms_compra
        Schema::create('sms_compra', function (Blueprint $table) {
            $table->id();
            $table->integer('conse_compra');
            $table->date('fecha_compra')->nullable();
            $table->double('monto_compra')->nullable();
            $table->integer('cantidad_compra')->nullable();
        });

        // 74. sms_enviados
        Schema::create('sms_enviados', function (Blueprint $table) {
            $table->id();
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
        });

        // 75. sms_envio_pac
        Schema::create('sms_envio_pac', function (Blueprint $table) {
            $table->id();
            $table->integer('nrohistoria');
            $table->integer('nroconsulta');
            $table->string('numero_cel', 14)->nullable();
            $table->string('texto_sms', 160)->nullable();
            $table->integer('orden')->nullable();
        });

        // 76. spt_collation_map
        Schema::create('spt_collation_map', function (Blueprint $table) {
            $table->id();
            $table->string('collation', 15)->nullable();
            $table->string('charsetn', 10)->nullable();
            $table->integer('number');
        });

        // 77. spt_jdatatype_info
        Schema::create('spt_jdatatype_info', function (Blueprint $table) {
            $table->id();
            $table->tinyInteger('ss_dtype');
            $table->string('TYPE_NAME', 30);
            $table->smallInteger('DATA_TYPE');
            $table->integer('typelength');
            $table->string('LITERAL_PREFIX', 32)->nullable();
            $table->string('LITERAL_SUFFIX', 32)->nullable();
            $table->string('CREATE_PARAMS', 32)->nullable();
            $table->smallInteger('NULLABLE');
            $table->smallInteger('CASE_SENSITIVE');
            $table->smallInteger('SEARCHABLE');
            $table->smallInteger('UNSIGNED_ATTRIBUTE')->nullable();
            $table->smallInteger('FIXED_PREC_SCALE');
            $table->smallInteger('AUTO_INCREMENT')->nullable();
            $table->string('LOCAL_TYPE_NAME', 128);
            $table->smallInteger('MINIMUM_SCALE')->nullable();
            $table->smallInteger('MAXIMUM_SCALE')->nullable();
            $table->smallInteger('SQL_DATA_TYPE')->nullable();
            $table->smallInteger('SQL_DATETIME_SUB')->nullable();
            $table->smallInteger('NUM_PREC_RADIX')->nullable();
            $table->boolean('is_unique');
        });

        // 78. spt_jdbc_conversion
        Schema::create('spt_jdbc_conversion', function (Blueprint $table) {
            $table->id();
            $table->integer('datatype');
            $table->string('conversion', 20)->nullable();
        });

        // 79. spt_jtext
        Schema::create('spt_jtext', function (Blueprint $table) {
            $table->id();
            $table->string('mdinfo', 30);
            $table->text('value')->nullable();
        });

        // 80. spt_mda
        Schema::create('spt_mda', function (Blueprint $table) {
            $table->id();
            $table->string('mdinfo', 30);
            $table->tinyInteger('querytype')->nullable();
            $table->string('query', 255)->nullable();
            $table->tinyInteger('mdaver_start')->nullable();
            $table->tinyInteger('mdaver_end');
            $table->integer('srvver_start')->nullable();
            $table->integer('srvver_end');
        });

        // 81. texto_doppler
        Schema::create('texto_doppler', function (Blueprint $table) {
            $table->id();
            $table->string('nivel', 10);
            $table->text('texto');
        });

        // 82. tipo_antecedente
        Schema::create('tipo_antecedente', function (Blueprint $table) {
            $table->id();
            $table->string('codetipo', 2);
            $table->string('descripcion', 40)->nullable();
            $table->string('tipoantecedente', 1)->nullable();
        });

        // 83. tipos_conceptos
        Schema::create('tipos_conceptos', function (Blueprint $table) {
            $table->id();
            $table->string('id_tipo_concepto', 4);
            $table->string('des_concepto', 100)->nullable();
        });

        // 84. tipos_documentos
        Schema::create('tipos_documentos', function (Blueprint $table) {
            $table->id();
            $table->string('tip_documento', 2);
            $table->string('des_documento', 100)->nullable();
        });

        // 85. tipos_examenes
        Schema::create('tipos_examenes', function (Blueprint $table) {
            $table->id();
            $table->string('codetipo', 10);
            $table->string('tipo', 40)->nullable();
        });

        // 86. tipos_recipe
        Schema::create('tipos_recipe', function (Blueprint $table) {
            $table->id();
            $table->string('codetipo', 10);
            $table->string('tipo', 40)->nullable();
        });

        // 87. ul_file
        Schema::create('ul_file', function (Blueprint $table) {
            $table->id();
            $table->integer('file_id');
            $table->string('name', 128);
            $table->string('project', 128);
            $table->text('filename')->nullable();
        });

        // 88. ul_statement
        Schema::create('ul_statement', function (Blueprint $table) {
            $table->id();
            $table->integer('file_id');
            $table->integer('statement_id');
            $table->integer('line');
            $table->text('sql');
            $table->string('name', 128);
            $table->string('cursor', 1);
            $table->string('do_forward', 1);
            $table->string('do_backward', 1);
            $table->string('do_insert', 1);
            $table->string('do_delete', 1);
            $table->string('do_update', 1);
            $table->string('code_segment', 8)->nullable();
        });

        // 89. ul_variable
        Schema::create('ul_variable', function (Blueprint $table) {
            $table->id();
            $table->integer('file_id');
            $table->integer('statement_id');
            $table->string('output', 1);
            $table->integer('sequence');
            $table->string('type_name', 20);
            $table->integer('type_length');
            $table->integer('domain_id');
            $table->integer('domain_length');
            $table->string('nulls', 1);
        });

        // 90. ultra_abdominal
        Schema::create('ultra_abdominal', function (Blueprint $table) {
            $table->id();
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
        });

        // 91. ultra_mama
        Schema::create('ultra_mama', function (Blueprint $table) {
            $table->id();
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
        });

        // 92. ultra_prostatico
        Schema::create('ultra_prostatico', function (Blueprint $table) {
            $table->id();
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
        });

        // 93. ultra_testiculos
        Schema::create('ultra_testiculos', function (Blueprint $table) {
            $table->id();
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
        });

        // 94. ultra_tiroides_musculo
        Schema::create('ultra_tiroides_musculo', function (Blueprint $table) {
            $table->id();
            $table->integer('historia');
            $table->integer('consulta');
            $table->date('fecha')->nullable();
            $table->string('transductor', 30)->nullable();
            $table->integer('referido')->nullable();
            $table->text('texto')->nullable();
            $table->text('puro_texto')->nullable();
            $table->text('conclusiones')->nullable();
        });

        // 95. vademecum
        Schema::create('vademecum', function (Blueprint $table) {
            $table->id();
            $table->string('codemedicina', 8);
            $table->string('nombregenerico', 35)->nullable();
            $table->string('nombrecomercial', 35)->nullable();
            $table->text('dosificacion')->nullable();
            $table->text('uso')->nullable();
            $table->string('presentacion', 50)->nullable();
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
        });

        // 96. vademecum_m
        Schema::create('vademecum_m', function (Blueprint $table) {
            $table->id();
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
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('vademecum_m');
        Schema::dropIfExists('vademecum');
        Schema::dropIfExists('ultra_tiroides_musculo');
        Schema::dropIfExists('ultra_testiculos');
        Schema::dropIfExists('ultra_prostatico');
        Schema::dropIfExists('ultra_mama');
        Schema::dropIfExists('ultra_abdominal');
        Schema::dropIfExists('ul_variable');
        Schema::dropIfExists('ul_statement');
        Schema::dropIfExists('ul_file');
        Schema::dropIfExists('tipos_recipe');
        Schema::dropIfExists('tipos_examenes');
        Schema::dropIfExists('tipos_documentos');
        Schema::dropIfExists('tipos_conceptos');
        Schema::dropIfExists('tipo_antecedente');
        Schema::dropIfExists('texto_doppler');
        Schema::dropIfExists('spt_mda');
        Schema::dropIfExists('spt_jtext');
        Schema::dropIfExists('spt_jdbc_conversion');
        Schema::dropIfExists('spt_jdatatype_info');
        Schema::dropIfExists('spt_collation_map');
        Schema::dropIfExists('sms_envio_pac');
        Schema::dropIfExists('sms_enviados');
        Schema::dropIfExists('sms_compra');
        Schema::dropIfExists('seg_emp');
        Schema::dropIfExists('rs_threads');
        Schema::dropIfExists('rs_lastcommit');
        Schema::dropIfExists('RowGenerator');
        Schema::dropIfExists('representante');
        Schema::dropIfExists('reposo_paciente');
        Schema::dropIfExists('registro_operaciones');
        Schema::dropIfExists('registro_empleados_eje_detalle');
        Schema::dropIfExists('registro_empleados_eje');
        Schema::dropIfExists('registro_empleados');
        Schema::dropIfExists('reg_empl_tipo_nomina');
        Schema::dropIfExists('reg_empl_frec_nomina');
        Schema::dropIfExists('referencia');
        Schema::dropIfExists('recipes_pareja');
        Schema::dropIfExists('recipes');
        Schema::dropIfExists('recipe2');
        Schema::dropIfExists('recipe_grupo_detalle');
        Schema::dropIfExists('recipe_grupo');
        Schema::dropIfExists('recipe_detalle');
        Schema::dropIfExists('radiologias');
        Schema::dropIfExists('radiologia_paciente');
        Schema::dropIfExists('radiologia_obs');
        Schema::dropIfExists('proveedor');
        Schema::dropIfExists('presupuesto_planti');
        Schema::dropIfExists('presupuesto_operatorio');
        Schema::dropIfExists('prena_exames_b');
        Schema::dropIfExists('pre_natal_observaciones');
        Schema::dropIfExists('pre_natal_examenes');
        Schema::dropIfExists('pre_natal_desarrollo_fino');
        Schema::dropIfExists('pre_natal_desarrollo');
        Schema::dropIfExists('pbcatvld');
        Schema::dropIfExists('pbcattbl');
        Schema::dropIfExists('pbcatfmt');
        Schema::dropIfExists('pbcatedt');
        Schema::dropIfExists('pbcatcol');
        Schema::dropIfExists('pago_quiru');
        Schema::dropIfExists('pacientes');
        Schema::dropIfExists('paciente_no_regi');
        Schema::dropIfExists('operadores');
        Schema::dropIfExists('motivos_consulta');
        Schema::dropIfExists('motivo_factura_prov');
        Schema::dropIfExists('motivo_factura');
        Schema::dropIfExists('motivo_consulta_paciente');
        Schema::dropIfExists('motivo_cita');
        Schema::dropIfExists('ml_user');
        Schema::dropIfExists('ml_table_script');
        Schema::dropIfExists('ml_table');
        Schema::dropIfExists('ml_subscription');
        Schema::dropIfExists('ml_scripts_modified');
        Schema::dropIfExists('ml_script_version');
        Schema::dropIfExists('ml_script');
        Schema::dropIfExists('ml_connection_script');
        Schema::dropIfExists('migrate_sql_defn');
        Schema::dropIfExists('migrate_remote_table_list');
        Schema::dropIfExists('migrate_remote_fks_list');
        Schema::dropIfExists('listado');
        Schema::dropIfExists('jdbc_function_escapes');
        Schema::dropIfExists('intenven_servi');
        Schema::dropIfExists('informe');
        Schema::dropIfExists('imagenes');
        Schema::dropIfExists('imagen_pacientes_2');
        Schema::dropIfExists('imagen_pacientes');
        Schema::dropIfExists('imagen_consulta');
        Schema::dropIfExists('hospitalizacion');
        Schema::dropIfExists('his_con_pre_factura');
        Schema::dropIfExists('formato_print');
        Schema::dropIfExists('facturas_compras_detalle');
        Schema::dropIfExists('facturas_compras');
        Schema::dropIfExists('factura_cliente');
        Schema::dropIfExists('EXCLUDEOBJECT');
        Schema::dropIfExists('examenes');
        Schema::dropIfExists('examen_pareja');
    }
};