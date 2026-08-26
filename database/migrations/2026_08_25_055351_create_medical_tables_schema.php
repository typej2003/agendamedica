<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('antece_paciente', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->integer('numhistoria');
            $table->string('codeantecedente', 4);
            $table->text('detalles')->nullable();
            $table->string('descripcion', 50)->nullable();
            $table->string('tipo', 2)->nullable();
            $table->string('tipo2', 1)->nullable();
        });

        Schema::create('antecedentes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->string('codantecedente', 4);
            $table->string('descripcion', 40)->nullable();
            $table->string('codtipo', 2)->nullable();
        });

        Schema::create('bancos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->string('cod_banco', 6);
            $table->string('nombre_banco', 150)->nullable();
        });

        Schema::create('baremo_quiru', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->integer('consecuti');
            $table->string('cod_inter', 3)->nullable();
            $table->string('cod_clini', 3)->nullable();
            $table->string('ced_paciente', 8)->nullable();
            $table->string('nom_interven', 300)->nullable();
            $table->string('nom_paciente', 200)->nullable();
            $table->date('fecha_creado')->nullable();
            $table->date('fecha_opera')->nullable();
            $table->time('hora_opera')->nullable();
            $table->double('monto_opera')->nullable();
            $table->string('tipo_rol', 1)->nullable();
            $table->string('pagada', 1)->nullable();
            $table->integer('medico_prin')->nullable();
            $table->integer('medico_aux')->nullable();
            $table->integer('historia')->nullable();
            $table->string('diagnostico', 100)->nullable();
            $table->double('monto_abono')->nullable();
            $table->double('monto_resta')->nullable();
            $table->string('empre', 30)->nullable();
            $table->time('hora_fin')->nullable();
            $table->integer('duracion')->nullable();
        });

        Schema::create('clinicas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->string('cod_clin', 3);
            $table->string('nom_clin', 50);
        });

        Schema::create('cola', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->date('fecha');
            $table->integer('numhistoria')->nullable();
            $table->integer('numorden')->nullable();
            $table->decimal('atendido', 1, 0)->nullable();
            $table->decimal('estado', 1, 0)->nullable();
            $table->string('turno', 1)->nullable();
            $table->string('motivo', 100)->nullable();
            $table->double('monto')->nullable();
            $table->time('hora_ini');
            $table->time('hora_fin')->nullable();
            $table->integer('tiempo')->nullable();
            $table->string('tipo', 10)->nullable();
            $table->integer('conse')->nullable();
            $table->string('sms', 1)->nullable();
            $table->string('sms_text', 160)->nullable();
            $table->integer('medico')->nullable();
        });

        Schema::create('cola_dia_no_labor', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->date('dia');
            $table->string('tipo', 10)->nullable();
            $table->string('motivo', 100)->nullable();
            $table->integer('medico');
        });

        Schema::create('constancia_obs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->integer('numhistoria');
            $table->integer('numconsulta');
            $table->text('observacion')->nullable();
            $table->string('titulo', 50)->nullable();
            $table->text('observacion01')->nullable();
        });

        Schema::create('consultas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->integer('numhistoria');
            $table->integer('nroconsulta');
            $table->date('fecha')->nullable();
            $table->longText('enfermedadactual')->nullable();
            $table->double('peso')->nullable();
            $table->double('talla')->nullable();
            $table->double('fc')->nullable();
            $table->double('pp')->nullable();
            $table->double('circcefalica')->nullable();
            $table->double('circtoraxica')->nullable();
            $table->double('circabdominal')->nullable();
            $table->string('tasentado', 7)->nullable();
            $table->string('taacostado', 7)->nullable();
            $table->string('tapie', 7)->nullable();
            $table->longText('resultadoexamencomp')->nullable();
            $table->string('eliminado', 1)->nullable();
            $table->longText('faringe')->nullable();
            $table->longText('nariz')->nullable();
            $table->longText('oido')->nullable();
            $table->longText('laringe')->nullable();
            $table->longText('cuello')->nullable();
            $table->longText('otros')->nullable();
            $table->longText('evolucion')->nullable();
            $table->longText('observaciones')->nullable();
            $table->integer('medico')->nullable();
            $table->string('sms', 1)->nullable();
        });

        Schema::create('consultorios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->string('codigo', 10);
            $table->string('consultorio', 100)->nullable();
        });

        Schema::create('cuentas_x_pagar', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->double('nro_cxp');
            $table->string('origen_cxp', 2)->nullable();
            $table->date('fec_cxp')->nullable();
            $table->string('edo_cxp', 1)->nullable();
            $table->string('tipo_cxp', 1)->nullable();
            $table->string('conc_cxp', 4)->nullable();
            $table->string('cxp_codigo', 20)->nullable();
            $table->string('cxp_descripcion', 150)->nullable();
            $table->date('fec_docum')->nullable();
            $table->string('cxp_nro_control', 25)->nullable();
            $table->string('cxp_nro_factura', 25)->nullable();
            $table->string('cxp_referencia', 25)->nullable();
            $table->double('cxp_mto_neto')->nullable();
            $table->double('cxp_ret_isrl')->nullable();
            $table->double('cxp_m_ret_isrl')->nullable();
            $table->double('cxp_iva')->nullable();
            $table->double('cxp_mto_iva')->nullable();
            $table->double('cxp_mto_total')->nullable();
            $table->double('cxp_ret_iva')->nullable();
            $table->double('cxp_m_ret_iva')->nullable();
            $table->double('cxp_mto_total_pagar')->nullable();
            $table->double('cxp_saldo_a_pagar')->nullable();
            $table->string('cxp_chekear', 1)->nullable();
            $table->string('cxp_forma', 1)->nullable();
        });

        Schema::create('cuentas_x_pagar_mov', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->double('nro_registro');
            $table->double('nro_cxp')->nullable();
            $table->double('nro_mov')->nullable();
            $table->date('fecha_emision')->nullable();
            $table->string('nro_documento', 100)->nullable();
            $table->string('tip_documento', 2)->nullable();
            $table->double('monto_pagar')->nullable();
        });

        Schema::create('dd_arterial_mi', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->integer('numhistoria');
            $table->integer('num_consulta');
            $table->string('indicacion', 100)->nullable();
            $table->decimal('referido_por', 15, 0)->nullable();
            $table->string('hc_text', 50)->nullable();
            $table->double('iliaca_ext_der')->nullable();
            $table->double('iliaca_ext_izq')->nullable();
            $table->double('feromonal_comun_der')->nullable();
            $table->double('feromonal_comun_izq')->nullable();
            $table->double('fer_superf_prox_der')->nullable();
            $table->double('fer_superf_prox_izq')->nullable();
            $table->double('fer_superf_distal_der')->nullable();
            $table->double('fer_superf_distal_izq')->nullable();
            $table->double('poplitea_der')->nullable();
            $table->double('poplitea_izq')->nullable();
            $table->double('tibial_ante_der')->nullable();
            $table->double('tibial_ante_izq')->nullable();
            $table->double('tibial_post_der')->nullable();
            $table->double('tibial_post_izq')->nullable();
            $table->double('peronea_der')->nullable();
            $table->double('peronea_izq')->nullable();
            $table->text('conclusion')->nullable();
            $table->string('equipo', 30)->nullable();
            $table->string('tansductor', 30)->nullable();
            $table->string('iliaca_ext', 5)->nullable();
            $table->string('feromonal_comun', 5)->nullable();
            $table->string('fer_superf_prox', 5)->nullable();
            $table->string('fer_superf_distal', 5)->nullable();
            $table->string('poplitea', 5)->nullable();
            $table->string('tibial_ante', 5)->nullable();
            $table->string('tibial_post', 5)->nullable();
            $table->string('peronea', 3)->nullable();
            $table->text('modo_b')->nullable();
            $table->text('modo_color')->nullable();
            $table->string('segunda_armonica', 1)->nullable();
            $table->string('contraste', 1)->nullable();
            $table->string('llenado', 1)->nullable();
            $table->text('full_text')->nullable();
            $table->text('sugerencia')->nullable();
            $table->text('puro_texto')->nullable();
        });

        Schema::create('department', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->integer('dept_id');
            $table->string('dept_name', 40);
            $table->integer('dept_head_id')->nullable();
        });

        Schema::create('detalles_factura_cliente', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->string('cod_inventario', 2);
            $table->integer('numfactura');
            $table->string('tipo_articulo', 25)->nullable();
            $table->string('nombre', 50)->nullable();
            $table->double('precio')->nullable();
            $table->double('descuento')->nullable();
            $table->integer('cantidad')->nullable();
            $table->date('fecha_doc')->nullable();
            $table->string('tipo_precio', 3);
            $table->double('total_articulo')->nullable();
        });

        Schema::create('detalles_presupuesto_plantilla', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->string('cod_inventario', 2);
            $table->integer('consecutivo');
            $table->string('tipo_articulo', 25)->nullable();
            $table->string('nombre', 50)->nullable();
            $table->double('precio')->nullable();
            $table->double('descuento')->nullable();
            $table->integer('cantidad')->nullable();
            $table->date('fecha_doc')->nullable();
            $table->string('tipo_documento', 3)->nullable();
            $table->double('total_articulo')->nullable();
        });

        Schema::create('diagnostico_paciente', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->integer('nrohistoria');
            $table->integer('nroconsulta');
            $table->string('codediagnostico', 5);
            $table->string('detalle_diagnostco', 100)->nullable();
            $table->integer('orden')->nullable();
        });

        Schema::create('diagnosticos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->string('codediagnostico', 5);
            $table->string('descripcion', 50);
        });

        Schema::create('dias_semana', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->date('fecha');
            $table->string('dia_semana', 10)->nullable();
            $table->integer('semana')->nullable();
            $table->integer('ano')->nullable();
            $table->string('descripcion', 100)->nullable();
            $table->string('tipo_dia', 10)->nullable();
        });

        Schema::create('dieta_paciente', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->integer('histroria');
            $table->integer('consulta');
            $table->string('cod_dieta', 2)->nullable();
            $table->text('descripcion')->nullable();
            $table->string('ejecutada', 1)->nullable();
            $table->integer('calorias')->nullable();
            $table->string('dieta', 200)->nullable();
        });

        Schema::create('doctores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->decimal('cedula', 15, 0);
            $table->string('apellidos', 30)->nullable();
            $table->string('nombres', 30)->nullable();
            $table->string('clinica', 60)->nullable();
            $table->text('direccion')->nullable();
            $table->string('telefono', 108)->nullable();
            $table->string('ciudad', 30)->nullable();
            $table->text('nota')->nullable();
            $table->string('codeespecial', 3);
        });

        Schema::create('DUMMY', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->integer('dummy_col');
        });

        Schema::create('eco_doppler', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->integer('historia');
            $table->integer('consulta');
            $table->string('arteria_uraina_menor', 20)->nullable();
            $table->string('arteria_uraina_mayor', 20)->nullable();
            $table->string('arteria_umbilical_menor', 20)->nullable();
            $table->string('arteria_umbilical_mayor', 20)->nullable();
            $table->string('arteria_cerebral_menor', 20)->nullable();
            $table->string('arteria_cerebral_mayor', 20)->nullable();
            $table->string('ductos_venosos', 1)->nullable();
            $table->string('ven_umbilical', 1)->nullable();
            $table->string('relac_aorta', 1)->nullable();
            $table->text('texto')->nullable();
            $table->text('conclusion')->nullable();
            $table->date('fecha')->nullable();
            $table->string('arteria_uraina_menor_izq', 20)->nullable();
            $table->string('arteria_uraina_mayor_izq', 20)->nullable();
            $table->text('sugerencias')->nullable();
            $table->integer('gesta')->nullable();
            $table->date('fur')->nullable();
            $table->string('relacion_ud', 20)->nullable();
            $table->string('relacion_ui', 20)->nullable();
            $table->string('relacion_u', 20)->nullable();
            $table->string('relacion_cm', 20)->nullable();
            $table->string('pico_ud', 20)->nullable();
            $table->string('pico_ui', 20)->nullable();
            $table->string('pico_u', 20)->nullable();
            $table->string('pico_cm', 20)->nullable();
            $table->string('para', 10)->nullable();
            $table->text('puro_texto')->nullable();
        });

        Schema::create('eco_obstetrico', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->integer('historia');
            $table->integer('consulta');
            $table->integer('gesta')->nullable();
            $table->date('fur')->nullable();
            $table->string('eg', 40)->nullable();
            $table->integer('saco_gestacional')->nullable();
            $table->string('circular_ovuide', 1)->nullable();
            $table->string('alargado', 1)->nullable();
            $table->string('retraido', 1)->nullable();
            $table->string('anormal', 1)->nullable();
            $table->string('movimiento_embrionario', 1)->nullable();
            $table->string('actividad_cardiaca', 1)->nullable();
            $table->integer('lcc')->nullable();
            $table->integer('dbp')->nullable();
            $table->string('huevo_anembrionado', 1)->nullable();
            $table->string('mola', 1)->nullable();
            $table->string('restos_abortivos', 1)->nullable();
            $table->string('embarazo_ectopico', 1)->nullable();
            $table->text('otros')->nullable();
            $table->text('observacion')->nullable();
            $table->text('dianostico')->nullable();
            $table->date('fecha')->nullable();
            $table->integer('tn')->nullable();
            $table->string('posicionutreo', 1)->nullable();
            $table->decimal('cuerpo', 5, 2)->nullable();
            $table->decimal('transverso', 5, 2)->nullable();
            $table->decimal('antero', 5, 2)->nullable();
            $table->decimal('ovarioderlong', 5, 2)->nullable();
            $table->decimal('ovarioderap', 5, 2)->nullable();
            $table->decimal('ovariodertras', 5, 2)->nullable();
            $table->decimal('ovarioizqlong', 5, 2)->nullable();
            $table->decimal('ovarioizqap', 5, 2)->nullable();
            $table->decimal('ovarioizqtras', 5, 2)->nullable();
            $table->string('cuerpo_utero', 1)->nullable();
            $table->decimal('der', 5, 2)->nullable();
            $table->decimal('izqu', 5, 2)->nullable();
            $table->decimal('lcr', 5, 2)->nullable();
            $table->decimal('sa_vit', 5, 2)->nullable();
            $table->decimal('t_nucal', 5, 2)->nullable();
            $table->decimal('dc_venoso', 5, 2)->nullable();
            $table->string('para', 10)->nullable();
            $table->text('puro_texto')->nullable();
            $table->string('posicionavf_d', 1)->nullable();
            $table->string('posicionrvf_d', 1)->nullable();
        });

        Schema::create('eco_obstetrico_tercer', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->integer('historia');
            $table->integer('consulta');
            $table->integer('gesta')->nullable();
            $table->date('fur')->nullable();
            $table->string('eg', 40)->nullable();
            $table->string('gestacion', 1)->nullable();
            $table->string('situacion', 1)->nullable();
            $table->string('dorso', 1)->nullable();
            $table->string('presentacion', 1)->nullable();
            $table->integer('bio_ca_bp')->nullable();
            $table->integer('bio_ca_cc')->nullable();
            $table->integer('bio_ca_of')->nullable();
            $table->integer('bio_in_ic')->nullable();
            $table->integer('bio_or_ioe')->nullable();
            $table->integer('bio_or_io')->nullable();
            $table->integer('bio_car_apc')->nullable();
            $table->integer('bio_car_apt')->nullable();
            $table->integer('bio_aorta')->nullable();
            $table->integer('bio_abdomen')->nullable();
            $table->integer('bio_ta')->nullable();
            $table->integer('bio_ca')->nullable();
            $table->integer('bio_humero')->nullable();
            $table->integer('bio_cubito')->nullable();
            $table->integer('bio_femur')->nullable();
            $table->integer('bio_tibia')->nullable();
            $table->integer('bio_sacro')->nullable();
            $table->integer('bio_peso_fetal')->nullable();
            $table->integer('bio_talla')->nullable();
            $table->string('bio_sexo', 1)->nullable();
            $table->string('ana_polo_cefalico', 1)->nullable();
            $table->string('ana_ventriculos_cerebrales', 1)->nullable();
            $table->string('ana_cerebelo', 1)->nullable();
            $table->string('ana_rostro_fetal', 1)->nullable();
            $table->string('ana_actitud_fetal', 1)->nullable();
            $table->string('ana_columna_vertebral', 1)->nullable();
            $table->string('ana_torax', 1)->nullable();
            $table->string('ana_relacion', 1)->nullable();
            $table->string('ana_corazon', 1)->nullable();
            $table->string('ana_corte_tracameral', 1)->nullable();
            $table->string('ana_tracto_de_salida', 1)->nullable();
            $table->string('ana_estomago', 1)->nullable();
            $table->string('ana_intestino', 1)->nullable();
            $table->string('ana_paredes_abdominales', 1)->nullable();
            $table->string('ana_rinone', 1)->nullable();
            $table->string('ana_vejiga', 1)->nullable();
            $table->string('ana_ex_brazo_an_md', 1)->nullable();
            $table->string('ana_ex_brazo_an_mi', 1)->nullable();
            $table->string('ana_ex_muslo_ppd', 1)->nullable();
            $table->string('ana_ex_muslo_ppi', 1)->nullable();
            $table->string('fun_liquido_amniotico', 1)->nullable();
            $table->integer('fun_indice_la')->nullable();
            $table->string('fun_dp_ubicacion', 1)->nullable();
            $table->string('fun_dp_grado', 10)->nullable();
            $table->integer('fun_dp_grosor')->nullable();
            $table->string('fun_dp_cordon_umbilical', 1)->nullable();
            $table->string('fun_dp_movimientos_respiratorios', 1)->nullable();
            $table->string('fun_dp_tono_fetal', 1)->nullable();
            $table->text('conclusion')->nullable();
            $table->text('observacion')->nullable();
            $table->date('fecha')->nullable();
            $table->string('movimientos_feto', 1)->nullable();
            $table->string('reactividad_cardiaca', 1)->nullable();
            $table->string('liquido_amniotico', 1)->nullable();
            $table->text('puro_texto')->nullable();
            $table->integer('gemelo')->nullable();
        });

        Schema::create('eco_obstetrico_tercer_2', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->integer('historia');
            $table->integer('consulta');
            $table->integer('gesta')->nullable();
            $table->date('fur')->nullable();
            $table->string('eg', 40)->nullable();
            $table->string('gestacion', 1)->nullable();
            $table->string('situacion', 1)->nullable();
            $table->string('dorso', 1)->nullable();
            $table->string('presentacion', 1)->nullable();
            $table->integer('bio_ca_bp')->nullable();
            $table->integer('bio_ca_cc')->nullable();
            $table->integer('bio_ca_of')->nullable();
            $table->integer('bio_in_ic')->nullable();
            $table->integer('bio_or_ioe')->nullable();
            $table->integer('bio_or_io')->nullable();
            $table->integer('bio_car_apc')->nullable();
            $table->integer('bio_car_apt')->nullable();
            $table->integer('bio_aorta')->nullable();
            $table->integer('bio_abdomen')->nullable();
            $table->integer('bio_ta')->nullable();
            $table->integer('bio_ca')->nullable();
            $table->integer('bio_humero')->nullable();
            $table->integer('bio_cubito')->nullable();
            $table->integer('bio_femur')->nullable();
            $table->integer('bio_tibia')->nullable();
            $table->integer('bio_sacro')->nullable();
            $table->integer('bio_peso_fetal')->nullable();
            $table->integer('bio_talla')->nullable();
            $table->string('bio_sexo', 1)->nullable();
            $table->string('ana_polo_cefalico', 1)->nullable();
            $table->string('ana_ventriculos_cerebrales', 1)->nullable();
            $table->string('ana_cerebelo', 1)->nullable();
            $table->string('ana_rostro_fetal', 1)->nullable();
            $table->string('ana_actitud_fetal', 1)->nullable();
            $table->string('ana_columna_vertebral', 1)->nullable();
            $table->string('ana_torax', 1)->nullable();
            $table->string('ana_relacion', 1)->nullable();
            $table->string('ana_corazon', 1)->nullable();
            $table->string('ana_corte_tracameral', 1)->nullable();
            $table->string('ana_tracto_de_salida', 1)->nullable();
            $table->string('ana_estomago', 1)->nullable();
            $table->string('ana_intestino', 1)->nullable();
            $table->string('ana_paredes_abdominales', 1)->nullable();
            $table->string('ana_rinone', 1)->nullable();
            $table->string('ana_vejiga', 1)->nullable();
            $table->string('ana_ex_brazo_an_md', 1)->nullable();
            $table->string('ana_ex_brazo_an_mi', 1)->nullable();
            $table->string('ana_ex_muslo_ppd', 1)->nullable();
            $table->string('ana_ex_muslo_ppi', 1)->nullable();
            $table->string('fun_liquido_amniotico', 1)->nullable();
            $table->integer('fun_indice_la')->nullable();
            $table->string('fun_dp_ubicacion', 1)->nullable();
            $table->string('fun_dp_grado', 10)->nullable();
            $table->integer('fun_dp_grosor')->nullable();
            $table->string('fun_dp_cordon_umbilical', 1)->nullable();
            $table->string('fun_dp_movimientos_respiratorios', 1)->nullable();
            $table->string('fun_dp_tono_fetal', 1)->nullable();
            $table->text('conclusion')->nullable();
            $table->text('observacion')->nullable();
            $table->date('fecha')->nullable();
            $table->string('movimientos_feto', 1)->nullable();
            $table->string('reactividad_cardiaca', 1)->nullable();
            $table->string('liquido_amniotico', 1)->nullable();
            $table->text('puro_texto')->nullable();
            $table->integer('gemelo')->nullable();
        });

        Schema::create('eco_obstetrico_tercer_2_o', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->integer('historia');
            $table->integer('consulta');
            $table->integer('gesta')->nullable();
            $table->date('fur')->nullable();
            $table->string('eg', 40)->nullable();
            $table->string('gestacion', 1)->nullable();
            $table->string('situacion', 1)->nullable();
            $table->string('dorso', 1)->nullable();
            $table->string('presentacion', 1)->nullable();
            $table->integer('bio_ca_bp')->nullable();
            $table->integer('bio_ca_cc')->nullable();
            $table->integer('bio_ca_of')->nullable();
            $table->integer('bio_in_ic')->nullable();
            $table->integer('bio_or_ioe')->nullable();
            $table->integer('bio_or_io')->nullable();
            $table->integer('bio_car_apc')->nullable();
            $table->integer('bio_car_apt')->nullable();
            $table->integer('bio_aorta')->nullable();
            $table->integer('bio_abdomen')->nullable();
            $table->integer('bio_ta')->nullable();
            $table->integer('bio_ca')->nullable();
            $table->integer('bio_humero')->nullable();
            $table->integer('bio_cubito')->nullable();
            $table->integer('bio_femur')->nullable();
            $table->integer('bio_tibia')->nullable();
            $table->integer('bio_sacro')->nullable();
            $table->integer('bio_peso_fetal')->nullable();
            $table->integer('bio_talla')->nullable();
            $table->string('bio_sexo', 1)->nullable();
            $table->string('ana_polo_cefalico', 1)->nullable();
            $table->string('ana_ventriculos_cerebrales', 1)->nullable();
            $table->string('ana_cerebelo', 1)->nullable();
            $table->string('ana_rostro_fetal', 1)->nullable();
            $table->string('ana_actitud_fetal', 1)->nullable();
            $table->string('ana_columna_vertebral', 1)->nullable();
            $table->string('ana_torax', 1)->nullable();
            $table->string('ana_relacion', 1)->nullable();
            $table->string('ana_corazon', 1)->nullable();
            $table->string('ana_corte_tracameral', 1)->nullable();
            $table->string('ana_tracto_de_salida', 1)->nullable();
            $table->string('ana_estomago', 1)->nullable();
            $table->string('ana_intestino', 1)->nullable();
            $table->string('ana_paredes_abdominales', 1)->nullable();
            $table->string('ana_rinone', 1)->nullable();
            $table->string('ana_vejiga', 1)->nullable();
            $table->string('ana_ex_brazo_an_md', 1)->nullable();
            $table->string('ana_ex_brazo_an_mi', 1)->nullable();
            $table->string('ana_ex_muslo_ppd', 1)->nullable();
            $table->string('ana_ex_muslo_ppi', 1)->nullable();
            $table->string('fun_liquido_amniotico', 1)->nullable();
            $table->integer('fun_indice_la')->nullable();
            $table->string('fun_dp_ubicacion', 1)->nullable();
            $table->string('fun_dp_grado', 10)->nullable();
            $table->integer('fun_dp_grosor')->nullable();
            $table->string('fun_dp_cordon_umbilical', 1)->nullable();
            $table->string('fun_dp_movimientos_respiratorios', 1)->nullable();
            $table->string('fun_dp_tono_fetal', 1)->nullable();
            $table->text('conclusion')->nullable();
            $table->text('observacion')->nullable();
            $table->date('fecha')->nullable();
            $table->string('movimientos_feto', 1)->nullable();
            $table->string('reactividad_cardiaca', 1)->nullable();
            $table->string('liquido_amniotico', 1)->nullable();
        });

        Schema::create('eco_obstetrico_tercer_o', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->integer('historia');
            $table->integer('consulta');
            $table->integer('gesta')->nullable();
            $table->date('fur')->nullable();
            $table->string('eg', 40)->nullable();
            $table->string('gestacion', 1)->nullable();
            $table->string('situacion', 1)->nullable();
            $table->string('dorso', 1)->nullable();
            $table->string('presentacion', 1)->nullable();
            $table->integer('bio_ca_bp')->nullable();
            $table->integer('bio_ca_cc')->nullable();
            $table->integer('bio_ca_of')->nullable();
            $table->integer('bio_in_ic')->nullable();
            $table->integer('bio_or_ioe')->nullable();
            $table->integer('bio_or_io')->nullable();
            $table->integer('bio_car_apc')->nullable();
            $table->integer('bio_car_apt')->nullable();
            $table->integer('bio_aorta')->nullable();
            $table->integer('bio_abdomen')->nullable();
            $table->integer('bio_ta')->nullable();
            $table->integer('bio_ca')->nullable();
            $table->integer('bio_humero')->nullable();
            $table->integer('bio_cubito')->nullable();
            $table->integer('bio_femur')->nullable();
            $table->integer('bio_tibia')->nullable();
            $table->integer('bio_sacro')->nullable();
            $table->integer('bio_peso_fetal')->nullable();
            $table->integer('bio_talla')->nullable();
            $table->string('bio_sexo', 1)->nullable();
            $table->string('ana_polo_cefalico', 1)->nullable();
            $table->string('ana_ventriculos_cerebrales', 1)->nullable();
            $table->string('ana_cerebelo', 1)->nullable();
            $table->string('ana_rostro_fetal', 1)->nullable();
            $table->string('ana_actitud_fetal', 1)->nullable();
            $table->string('ana_columna_vertebral', 1)->nullable();
            $table->string('ana_torax', 1)->nullable();
            $table->string('ana_relacion', 1)->nullable();
            $table->string('ana_corazon', 1)->nullable();
            $table->string('ana_corte_tracameral', 1)->nullable();
            $table->string('ana_tracto_de_salida', 1)->nullable();
            $table->string('ana_estomago', 1)->nullable();
            $table->string('ana_intestino', 1)->nullable();
            $table->string('ana_paredes_abdominales', 1)->nullable();
            $table->string('ana_rinone', 1)->nullable();
            $table->string('ana_vejiga', 1)->nullable();
            $table->string('ana_ex_brazo_an_md', 1)->nullable();
            $table->string('ana_ex_brazo_an_mi', 1)->nullable();
            $table->string('ana_ex_muslo_ppd', 1)->nullable();
            $table->string('ana_ex_muslo_ppi', 1)->nullable();
            $table->string('fun_liquido_amniotico', 1)->nullable();
            $table->integer('fun_indice_la')->nullable();
            $table->string('fun_dp_ubicacion', 1)->nullable();
            $table->string('fun_dp_grado', 10)->nullable();
            $table->integer('fun_dp_grosor')->nullable();
            $table->string('fun_dp_cordon_umbilical', 1)->nullable();
            $table->string('fun_dp_movimientos_respiratorios', 1)->nullable();
            $table->string('fun_dp_tono_fetal', 1)->nullable();
            $table->text('conclusion')->nullable();
            $table->text('observacion')->nullable();
            $table->date('fecha')->nullable();
            $table->string('movimientos_feto', 1)->nullable();
            $table->string('reactividad_cardiaca', 1)->nullable();
            $table->string('liquido_amniotico', 1)->nullable();
        });

        Schema::create('eco_pelvico', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->integer('numhistoria');
            $table->date('fecha');
            $table->string('posicionavf', 1)->nullable();
            $table->string('centrar', 1)->nullable();
            $table->string('patron', 1)->nullable();
            $table->decimal('cuerpo', 5, 2)->nullable();
            $table->decimal('antero', 5, 2)->nullable();
            $table->decimal('transverso', 5, 2)->nullable();
            $table->decimal('cuello', 5, 2)->nullable();
            $table->decimal('anterocuello', 5, 2)->nullable();
            $table->decimal('espesor', 5, 2)->nullable();
            $table->string('linea', 1)->nullable();
            $table->text('observacion')->nullable();
            $table->integer('nroconsulta');
            $table->decimal('ovarioderlong', 5, 2)->nullable();
            $table->decimal('ovarioderap', 5, 2)->nullable();
            $table->decimal('ovariodertras', 5, 2)->nullable();
            $table->decimal('ovarioizqlong', 5, 2)->nullable();
            $table->decimal('ovarioizqap', 5, 2)->nullable();
            $table->decimal('ovarioizqtras', 5, 2)->nullable();
            $table->string('fondosacolibre', 1)->nullable();
            $table->string('fondosacootro', 80)->nullable();
            $table->text('idxecografico')->nullable();
            $table->text('sugerencias')->nullable();
            $table->string('formautero', 30)->nullable();
            $table->text('observacionovario')->nullable();
            $table->string('bordeutero', 1)->nullable();
            $table->date('fur')->nullable();
            $table->integer('gesta')->nullable();
            $table->string('trans_convex', 10)->nullable();
            $table->string('trans_transvaginal', 10)->nullable();
            $table->string('vagina', 10)->nullable();
            $table->double('volumen_der')->nullable();
            $table->double('volumen_izq')->nullable();
            $table->string('vagina_llena', 1)->nullable();
            $table->string('posicionavf_d', 1)->nullable();
            $table->string('posicionrvf_d', 1)->nullable();
            $table->string('linea_continuo', 1)->nullable();
            $table->string('linea_homo', 1)->nullable();
            $table->string('linea_cavidad_u', 1)->nullable();
            $table->string('PARA', 10)->nullable();
            $table->text('puro_texto')->nullable();
        });

        Schema::create('ecocadiograma_fetal', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->integer('historia');
            $table->integer('consulta');
            $table->integer('biome_dbp')->nullable();
            $table->integer('biome_dof')->nullable();
            $table->integer('biome_cc')->nullable();
            $table->integer('biome_relacion')->nullable();
            $table->integer('biome_cardiaca')->nullable();
            $table->integer('biome_toraxica')->nullable();
            $table->integer('biome_ca')->nullable();
            $table->integer('biome_humero')->nullable();
            $table->integer('biome_femur')->nullable();
            $table->integer('biome_saco')->nullable();
            $table->text('anatomi_datos')->nullable();
            $table->string('pla_funi_placenta', 1)->nullable();
            $table->string('pla_funi_grado', 10)->nullable();
            $table->integer('pla_funi_grosor')->nullable();
            $table->string('pla_funi_cordon_umb', 10)->nullable();
            $table->string('pla_funi_liquido_anm', 10)->nullable();
            $table->integer('pla_funi_pef')->nullable();
            $table->string('pla_funi_sexo', 1)->nullable();
            $table->text('conclusion')->nullable();
            $table->text('sugerencia')->nullable();
            $table->date('fecha')->nullable();
            $table->integer('gesta')->nullable();
            $table->integer('cesarea')->nullable();
            $table->integer('parto')->nullable();
            $table->integer('aborto')->nullable();
            $table->string('otros', 20)->nullable();
            $table->date('fur')->nullable();
            $table->integer('eg')->nullable();
            $table->integer('referido')->nullable();
            $table->text('texto_inicio')->nullable();
        });

        Schema::create('emision_pagos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->double('nro_emision');
            $table->date('fecha_emision')->nullable();
            $table->string('descripcion', 100)->nullable();
            $table->string('cod_banco', 6)->nullable();
            $table->string('estado', 1)->nullable();
            $table->double('monto_pagar')->nullable();
        });

        Schema::create('emision_pagos_detalle', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->double('nro_registro');
            $table->double('nro_emision')->nullable();
            $table->double('nro_cxp')->nullable();
            $table->double('nro_mov')->nullable();
            $table->string('origen_cxp', 2)->nullable();
            $table->string('cxp_codigo', 20)->nullable();
            $table->string('cxp_descripcion', 150)->nullable();
            $table->date('fecha_emision')->nullable();
            $table->string('nro_documento', 100)->nullable();
            $table->string('tip_documento', 2)->nullable();
            $table->double('saldo_pagar')->nullable();
            $table->double('monto_pagar')->nullable();
            $table->string('estado', 1)->nullable();
        });

        Schema::create('employee', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->integer('emp_id');
            $table->integer('manager_id')->nullable();
            $table->string('emp_fname', 30);
            $table->string('emp_lname', 30);
            $table->integer('dept_id');
            $table->string('street', 40);
            $table->string('city', 20);
            $table->string('state', 4);
            $table->string('zip_code', 9);
            $table->string('phone', 10)->nullable();
            $table->string('status', 1)->nullable();
            $table->string('ss_number', 11);
            $table->decimal('salary', 20, 3);
            $table->date('start_date');
            $table->date('termination_date')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('bene_health_ins', 1)->nullable();
            $table->string('bene_life_ins', 1)->nullable();
            $table->string('bene_day_care', 1)->nullable();
            $table->string('sex', 1)->nullable();
        });

        Schema::create('especial', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->string('codeespecial', 3);
            $table->string('especialidad', 50)->nullable();
        });

        Schema::create('evolucion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->string('especialidad', 200)->nullable();
            $table->string('ciudad', 20)->nullable();
            $table->string('logo', 500)->nullable();
            $table->string('linea_1', 90)->nullable();
            $table->string('linea_2', 90)->nullable();
            $table->string('linea_3', 90)->nullable();
            $table->string('lineag_1', 115)->nullable();
            $table->string('lineag_2', 115)->nullable();
            $table->integer('clave');
            $table->date('fecha')->nullable();
            $table->string('rif', 40)->nullable();
            $table->string('reporte_vacio', 1)->nullable();
            $table->string('moneda', 10)->nullable();
            $table->string('impuesto', 20)->nullable();
            $table->double('por_impues')->nullable();
            $table->string('am_pm', 1)->nullable();
            $table->integer('cantidad_paciente')->nullable();
            $table->time('lunes_i')->nullable();
            $table->time('lunes_f')->nullable();
            $table->time('martes_i')->nullable();
            $table->time('martes_f')->nullable();
            $table->time('miercoles_i')->nullable();
            $table->time('miercoles_f')->nullable();
            $table->time('jueves_i')->nullable();
            $table->time('jueves_f')->nullable();
            $table->time('vienes_i')->nullable();
            $table->time('viernes_f')->nullable();
            $table->time('sabado_i')->nullable();
            $table->time('sabado_f')->nullable();
            $table->integer('tiempo_paci')->nullable();
            $table->time('domingo_i')->nullable();
            $table->time('domigo_f')->nullable();
            $table->string('lunes', 1)->nullable();
            $table->string('martes', 1)->nullable();
            $table->string('miercoles', 1)->nullable();
            $table->string('jueves', 1)->nullable();
            $table->string('viernes', 1)->nullable();
            $table->string('sabado', 1)->nullable();
            $table->string('domingo', 1)->nullable();
            $table->string('feriado', 100)->nullable();
            $table->string('cedula', 20)->nullable();
            $table->string('min_salud', 20)->nullable();
            $table->string('col_med', 20)->nullable();
            $table->string('cita_previa', 1)->nullable();
            $table->string('telefono', 20)->nullable();
            $table->string('cobra_honorarios', 1)->nullable();
            $table->decimal('por_cobranza', 3, 2)->nullable();
            $table->decimal('por_retencin_seg', 3, 2)->nullable();
            $table->decimal('por_retencin_part', 3, 2)->nullable();
            $table->string('accionista', 1)->nullable();
            $table->string('consultorio', 10)->nullable();
            $table->string('contrasena', 20)->nullable();
            $table->string('paga_iva', 1)->nullable();
            $table->string('sms_user', 20)->nullable();
            $table->string('sms_clave', 20)->nullable();
            $table->integer('sms_cantidad_total')->nullable();
            $table->string('sms_telefono_llamada', 30)->nullable();
            $table->string('sms_sexo_medico', 1)->nullable();
            $table->string('sms_proveedor', 1)->nullable();
            $table->string('correo_med', 100)->nullable();
            $table->string('pais', 20)->nullable();
            $table->string('prefi_1', 2)->nullable();
            $table->string('prefi_2', 2)->nullable();
            $table->string('prefi_3', 2)->nullable();
            $table->string('nom_moneda', 20)->nullable();
            $table->string('nom_impuesto', 20)->nullable();
            $table->double('impuesto_vale')->nullable();
            $table->string('reg-medico', 20)->nullable();
            $table->string('slug', 255)->nullable();
        });

        Schema::create('evolucion_copy', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->string('especialidad', 200)->nullable();
            $table->string('ciudad', 20)->nullable();
            $table->string('logo', 500)->nullable();
            $table->string('linea_1', 90)->nullable();
            $table->string('linea_2', 90)->nullable();
            $table->string('linea_3', 90)->nullable();
            $table->string('lineag_1', 115)->nullable();
            $table->string('lineag_2', 115)->nullable();
            $table->integer('clave');
            $table->date('fecha')->nullable();
            $table->string('rif', 40)->nullable();
            $table->string('reporte_vacio', 1)->nullable();
            $table->string('moneda', 10)->nullable();
            $table->string('impuesto', 20)->nullable();
            $table->double('por_impues')->nullable();
            $table->string('am_pm', 1)->nullable();
            $table->integer('cantidad_paciente')->nullable();
            $table->time('lunes_i')->nullable();
            $table->time('lunes_f')->nullable();
            $table->time('martes_i')->nullable();
            $table->time('martes_f')->nullable();
            $table->time('miercoles_i')->nullable();
            $table->time('miercoles_f')->nullable();
            $table->time('jueves_i')->nullable();
            $table->time('jueves_f')->nullable();
            $table->time('vienes_i')->nullable();
            $table->time('viernes_f')->nullable();
            $table->time('sabado_i')->nullable();
            $table->time('sabado_f')->nullable();
            $table->integer('tiempo_paci')->nullable();
            $table->time('domingo_i')->nullable();
            $table->time('domigo_f')->nullable();
            $table->string('lunes', 1)->nullable();
            $table->string('martes', 1)->nullable();
            $table->string('miercoles', 1)->nullable();
            $table->string('jueves', 1)->nullable();
            $table->string('viernes', 1)->nullable();
            $table->string('sabado', 1)->nullable();
            $table->string('domingo', 1)->nullable();
            $table->string('feriado', 100)->nullable();
            $table->string('cedula', 20)->nullable();
            $table->string('min_salud', 20)->nullable();
            $table->string('col_med', 20)->nullable();
            $table->string('cita_previa', 1)->nullable();
            $table->string('telefono', 20)->nullable();
            $table->string('cobra_honorarios', 1)->nullable();
            $table->decimal('por_cobranza', 3, 2)->nullable();
            $table->decimal('por_retencin_seg', 3, 2)->nullable();
            $table->decimal('por_retencin_part', 3, 2)->nullable();
            $table->string('accionista', 1)->nullable();
            $table->string('consultorio', 10)->nullable();
            $table->string('contrasena', 20)->nullable();
            $table->string('paga_iva', 1)->nullable();
            $table->string('sms_user', 20)->nullable();
            $table->string('sms_clave', 20)->nullable();
            $table->integer('sms_cantidad_total')->nullable();
            $table->string('sms_telefono_llamada', 30)->nullable();
            $table->string('sms_sexo_medico', 1)->nullable();
            $table->string('sms_proveedor', 1)->nullable();
            $table->string('correo_med', 100)->nullable();
        });

        Schema::create('examen_fisico', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->integer('nrohistoria');
            $table->integer('nroconsulta');
            $table->text('cardiopulmonal')->nullable();
            $table->text('abdomen')->nullable();
            $table->text('extremidades')->nullable();
            $table->text('otros')->nullable();
            $table->text('electrocardiograma')->nullable();
            $table->text('rx_de_torax')->nullable();
            $table->text('laboratorio')->nullable();
            $table->text('riesgo_operatorio')->nullable();
            $table->text('sugerencias')->nullable();
        });

        Schema::create('examen_fisico_nuevo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->integer('historia');
            $table->integer('consulta');
            $table->integer('talla')->nullable();
            $table->integer('peso')->nullable();
            $table->text('mamas')->nullable();
            $table->text('tiroides')->nullable();
            $table->text('abdomen')->nullable();
            $table->text('extremidades')->nullable();
            $table->text('cardiopulmonar')->nullable();
            $table->text('genitales_externos')->nullable();
            $table->text('especulos')->nullable();
            $table->text('tacto_vaginal')->nullable();
            $table->text('tacto_rectal')->nullable();
            $table->text('colposcopia')->nullable();
            $table->string('tension', 8)->nullable();
            $table->text('general')->nullable();
            $table->text('neurologico')->nullable();
            $table->text('mama_derecha')->nullable();
            $table->text('total_g')->nullable();
            $table->text('total_f')->nullable();
            $table->text('total_general')->nullable();
        });

        Schema::create('examen_obs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->integer('numhistoria');
            $table->integer('numconsulta');
            $table->text('observacion')->nullable();
        });

        Schema::create('examen_paciente', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->integer('nrohistoria');
            $table->integer('nroconsulta');
            $table->string('codeexamen', 8);
            $table->string('resultado', 30)->nullable();
            $table->string('vinculante', 1)->nullable();
        });

        Schema::create('examen_pareja', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->integer('nrohistoria');
            $table->integer('nroconsulta');
            $table->string('codeexamen', 8);
            $table->integer('orden')->nullable();
            $table->string('cedula', 10)->nullable();
            $table->string('nombre', 200)->nullable();
            $table->string('procedencia', 4)->nullable();
        });

        Schema::create('examenes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->string('codeexamen', 8);
            $table->string('examen', 45)->nullable();
            $table->string('codetipo', 10)->nullable();
        });

        Schema::create('EXCLUDEOBJECT', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->string('name', 128);
            $table->string('type', 1);
        });

        Schema::create('factura_cliente', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
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

        Schema::create('facturas_compras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
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

        Schema::create('facturas_compras_detalle', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->double('id_registro');
            $table->string('id_fac_prov', 6)->nullable();
            $table->string('des_concepto', 100)->nullable();
            $table->double('cantidad')->nullable();
            $table->double('monto')->nullable();
            $table->double('monto_total')->nullable();
            $table->double('id_factura_compra')->nullable();
        });

        Schema::create('formato_print', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->string('codigo', 4);
            $table->string('titulo', 200)->nullable();
            $table->text('descripcion')->nullable();
            $table->string('tipo', 10)->nullable();
        });

        Schema::create('his_con_pre_factura', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->double('id_registro');
            $table->integer('numhistoria')->nullable();
            $table->integer('nroconsulta')->nullable();
            $table->string('codigo', 8)->nullable();
            $table->string('descripcion', 45)->nullable();
            $table->double('cantidad')->nullable();
            $table->double('monto')->nullable();
        });

        Schema::create('hospitalizacion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->integer('nrohistoria');
            $table->integer('nroconsulta');
            $table->text('diagprev')->nullable();
            $table->text('indiprev')->nullable();
            $table->text('dieta')->nullable();
            $table->string('habitacion', 1)->nullable();
            $table->string('hospital', 60)->nullable();
        });

        Schema::create('imagen_consulta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->integer('nrohistoria');
            $table->integer('nroconsulta');
            $table->text('observacion')->nullable();
            $table->string('imagen', 256);
            $table->integer('orden')->nullable();
        });

        Schema::create('imagen_pacientes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->integer('nrohistoria');
            $table->string('imagen', 256);
            $table->string('imagen2', 256)->nullable();
        });

        Schema::create('imagen_pacientes_2', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->integer('nrohistoria');
            $table->string('imagen', 256)->nullable();
            $table->string('imagen2', 256)->nullable();
        });

        Schema::create('imagenes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->string('descripcion', 30);
            $table->string('imagen', 256);
        });

        Schema::create('informe', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->integer('nrohistoria');
            $table->integer('nroconsulta');
            $table->string('para', 50)->nullable();
            $table->text('descripcion');
            $table->date('fe_cha')->nullable();
        });

        Schema::create('intenven_servi', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->string('codigo', 3);
            $table->string('nombre', 300)->nullable();
            $table->double('precio_principal')->nullable();
            $table->double('precio_auxiliar')->nullable();
        });

        Schema::create('jdbc_function_escapes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->string('escape_name', 40);
            $table->string('map_string', 40)->nullable();
        });

        Schema::create('listado', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->string('tipo', 5);
            $table->string('descripcion_tipo', 30);
            $table->string('segundo', 5);
        });

        Schema::create('migrate_remote_fks_list', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
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

        Schema::create('migrate_remote_table_list', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
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

        Schema::create('migrate_sql_defn', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->text('unld_str')->nullable();
            $table->text('et_table_id')->nullable();
        });

        Schema::create('ml_connection_script', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->integer('version_id');
            $table->string('event', 128);
            $table->integer('script_id');
        });

        Schema::create('ml_script', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->integer('script_id');
            $table->text('script');
            $table->string('script_language', 128);
        });

        Schema::create('ml_script_version', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->integer('version_id');
            $table->string('name', 128);
            $table->text('description')->nullable();
        });

        Schema::create('ml_scripts_modified', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->dateTime('last_modified');
        });

        Schema::create('ml_subscription', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->integer('user_id');
            $table->string('publication_name', 128);
            $table->decimal('progress', 20, 0);
        });

        Schema::create('ml_table', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->integer('table_id');
            $table->string('name', 128);
        });

        Schema::create('ml_table_script', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->integer('version_id');
            $table->integer('table_id');
            $table->string('event', 128);
            $table->integer('script_id');
        });

        Schema::create('ml_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->integer('user_id');
            $table->string('name', 128);
            $table->integer('commit_state');
            $table->decimal('progress', 20, 0);
            $table->binary('hashed_password')->nullable();
        });

        Schema::create('motivo_cita', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->string('codigo', 10);
            $table->string('tipo_atencion', 100)->nullable();
        });

        Schema::create('motivo_consulta_paciente', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->string('codemotivo', 4);
            $table->integer('nrohistoria');
            $table->integer('nroconsulta');
            $table->string('descripcion', 50)->nullable();
            $table->string('detalle', 300)->nullable();
        });

        Schema::create('motivo_factura', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->string('codigo', 8);
            $table->string('descripcion', 45)->nullable();
            $table->double('monto');
            $table->double('monto_seg')->nullable();
            $table->string('tipo', 1)->nullable();
        });

        Schema::create('motivo_factura_prov', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->string('id_fac_prov', 6);
            $table->string('des_concepto', 100)->nullable();
            $table->double('monto')->nullable();
        });

        Schema::create('motivos_consulta', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->string('codemotivo', 4);
            $table->string('descripcion', 40);
        });

        Schema::create('operadores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->string('primera', 15);
            $table->string('segunda', 10);
            $table->string('nonbre', 50)->nullable();
            $table->string('nivel', 1)->nullable();
        });

        Schema::create('paciente_no_regi', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
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

        Schema::create('pago_quiru', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->integer('cod_pagos');
            $table->integer('consecuti');
            $table->date('fecha')->nullable();
            $table->double('monto_total')->nullable();
            $table->double('abono')->nullable();
            $table->double('resta')->nullable();
            $table->string('pago', 1)->nullable();
            $table->time('hora_pago')->nullable();
        });

        Schema::create('pbcatcol', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
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

        Schema::create('pbcatedt', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->string('pbe_name', 30);
            $table->string('pbe_edit', 254)->nullable();
            $table->smallInteger('pbe_type')->nullable();
            $table->integer('pbe_cntr')->nullable();
            $table->smallInteger('pbe_seqn');
            $table->integer('pbe_flag')->nullable();
            $table->string('pbe_work', 32)->nullable();
        });

        Schema::create('pbcatfmt', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->string('pbf_name', 30);
            $table->string('pbf_frmt', 254)->nullable();
            $table->smallInteger('pbf_type')->nullable();
            $table->integer('pbf_cntr')->nullable();
        });

        Schema::create('pbcattbl', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
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

        Schema::create('pbcatvld', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->string('pbv_name', 30);
            $table->string('pbv_vald', 254)->nullable();
            $table->smallInteger('pbv_type')->nullable();
            $table->integer('pbv_cntr')->nullable();
            $table->string('pbv_msg', 254)->nullable();
        });

        Schema::create('pre_natal_desarrollo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
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

        Schema::create('pre_natal_desarrollo_fino', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
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

        Schema::create('pre_natal_examenes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
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

        Schema::create('pre_natal_observaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
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

        Schema::create('prena_exames_b', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
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

        Schema::create('presupuesto_operatorio', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
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

        Schema::create('presupuesto_planti', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->integer('consecutivo');
            $table->string('tipo_precio', 1)->nullable();
            $table->double('total_costo')->nullable();
            $table->double('total_final')->nullable();
            $table->text('notas')->nullable();
            $table->string('tipo_doc', 3)->nullable();
            $table->string('nom_presupuesto', 100)->nullable();
        });

        Schema::create('proveedor', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->string('cod_prov', 6);
            $table->string('proveedor', 150)->nullable();
            $table->string('rif', 20)->nullable();
            $table->string('direccion', 300)->nullable();
            $table->string('telefono', 20)->nullable();
            $table->string('contacto', 100)->nullable();
            $table->string('celular', 20)->nullable();
        });

        Schema::create('radiologia_obs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->integer('numhistoria');
            $table->integer('numconsulta');
            $table->text('observacion')->nullable();
        });

        Schema::create('radiologia_paciente', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->integer('nrohistoria');
            $table->integer('nroconsulta');
            $table->string('coderadio', 8);
            $table->integer('nroopcion')->nullable();
            $table->integer('orden')->nullable();
        });

        Schema::create('radiologias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->string('coderadio', 8);
            $table->string('estudio', 45)->nullable();
            $table->string('codetipo', 10)->nullable();
            $table->text('opciones')->nullable();
            $table->string('tipo', 40)->nullable();
        });

        Schema::create('recipe_detalle', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->integer('nrohistoria');
            $table->integer('nroconsulta');
            $table->integer('recipe');
            $table->date('fe_emision')->nullable();
            $table->integer('fe_vence')->nullable();
            $table->text('nota')->nullable();
        });

        Schema::create('recipe_grupo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->string('codigo', 4);
            $table->string('tratamiento', 100)->nullable();
        });

        Schema::create('recipe_grupo_detalle', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
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

        Schema::create('recipe2', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
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

        Schema::create('recipes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
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

        Schema::create('recipes_pareja', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
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

        Schema::create('referencia', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->integer('nrohistoria');
            $table->integer('nroconsulta');
            $table->decimal('ceduladoctor', 15, 0);
            $table->text('referencia')->nullable();
        });

        Schema::create('reg_empl_frec_nomina', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->string('frecuencia_nomina', 2);
            $table->string('nombre_frecuencia', 50)->nullable();
        });

        Schema::create('reg_empl_tipo_nomina', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->string('tipo_nomina', 2);
            $table->string('nombre_nomina', 50)->nullable();
        });

        Schema::create('registro_empleados', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
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

        Schema::create('registro_empleados_eje', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->double('nro_nomina');
            $table->string('descripcion', 100)->nullable();
            $table->date('fecha_aplicacion')->nullable();
            $table->string('status', 1)->nullable();
            $table->string('tipo_nomina', 2)->nullable();
            $table->string('frecuencia_nomina', 2)->nullable();
            $table->double('monto_total')->nullable();
        });

        Schema::create('registro_empleados_eje_detalle', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->double('nro_nomina');
            $table->double('nro_empleado');
            $table->double('nro_cxp')->nullable();
            $table->double('monto_empleado')->nullable();
        });

        Schema::create('registro_operaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
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

        Schema::create('reposo_paciente', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->integer('nrohistoria');
            $table->integer('nroconsulta');
            $table->string('codereposo', 1)->nullable();
            $table->date('fdesde')->nullable();
            $table->integer('numdias')->nullable();
            $table->text('obser_reposo')->nullable();
        });

        Schema::create('representante', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->integer('numhistoria');
            $table->string('nombre', 40)->nullable();
            $table->string('codeparentesco', 1)->nullable();
            $table->string('direccion', 60)->nullable();
        });

        Schema::create('RowGenerator', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->smallInteger('row_num');
        });

        Schema::create('rs_lastcommit', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->integer('origin');
            $table->binary('origin_qid')->nullable();
            $table->binary('secondary_qid')->nullable();
            $table->dateTime('origin_time')->nullable();
            $table->dateTime('commit_time')->nullable();
        });

        Schema::create('rs_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->integer('seq')->nullable();
        });

        Schema::create('seg_emp', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->string('codesegemp', 3);
            $table->string('nombre', 150)->nullable();
            $table->string('rif', 50)->nullable();
            $table->string('direccion', 350)->nullable();
            $table->string('telef', 50)->nullable();
        });

        Schema::create('sms_compra', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->integer('conse_compra');
            $table->date('fecha_compra')->nullable();
            $table->double('monto_compra')->nullable();
            $table->integer('cantidad_compra')->nullable();
        });

        Schema::create('sms_enviados', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
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

        Schema::create('sms_envio_pac', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->integer('nrohistoria');
            $table->integer('nroconsulta');
            $table->string('numero_cel', 14)->nullable();
            $table->string('texto_sms', 160)->nullable();
            $table->integer('orden')->nullable();
        });

        Schema::create('spt_collation_map', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->string('collation', 15)->nullable();
            $table->string('charsetn', 10)->nullable();
            $table->integer('number');
        });

        Schema::create('spt_jdatatype_info', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
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

        Schema::create('spt_jdbc_conversion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->integer('datatype');
            $table->string('conversion', 20)->nullable();
        });

        Schema::create('spt_jtext', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->string('mdinfo', 30);
            $table->text('value')->nullable();
        });

        Schema::create('spt_mda', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->string('mdinfo', 30);
            $table->tinyInteger('querytype')->nullable();
            $table->string('query', 255)->nullable();
            $table->tinyInteger('mdaver_start')->nullable();
            $table->tinyInteger('mdaver_end');
            $table->integer('srvver_start')->nullable();
            $table->integer('srvver_end');
        });

        Schema::create('texto_doppler', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->string('nivel', 10);
            $table->text('texto');
        });

        Schema::create('tipo_antecedente', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->string('codetipo', 2);
            $table->string('descripcion', 40)->nullable();
            $table->string('tipoantecedente', 1)->nullable();
        });

        Schema::create('tipos_conceptos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->string('id_tipo_concepto', 4);
            $table->string('des_concepto', 100)->nullable();
        });

        Schema::create('tipos_documentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->string('tip_documento', 2);
            $table->string('des_documento', 100)->nullable();
        });

        Schema::create('tipos_examenes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->string('codetipo', 10);
            $table->string('tipo', 40)->nullable();
        });

        Schema::create('tipos_recipe', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->string('codetipo', 10);
            $table->string('tipo', 40)->nullable();
        });

        Schema::create('ul_file', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
            $table->integer('file_id');
            $table->string('name', 128);
            $table->string('project', 128);
            $table->text('filename')->nullable();
        });

        Schema::create('ul_statement', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
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

        Schema::create('ul_variable', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
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

        Schema::create('ultra_abdominal', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
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

        Schema::create('ultra_mama', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
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

        Schema::create('ultra_prostatico', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medico_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('medicalcenter_id')->nullable()->constrained('medical_centers')->onDelete('cascade');
            $table->foreignId('office_id')->nullable()->constrained('offices')->onDelete('cascade');
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
            $table->text('conclusiones')->nullable();
            $table->text('puro_texto')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
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
        Schema::dropIfExists('examen_paciente');
        Schema::dropIfExists('examen_obs');
        Schema::dropIfExists('examen_fisico_nuevo');
        Schema::dropIfExists('examen_fisico');
        Schema::dropIfExists('evolucion_copy');
        Schema::dropIfExists('evolucion');
        Schema::dropIfExists('especial');
        Schema::dropIfExists('employee');
        Schema::dropIfExists('emision_pagos_detalle');
        Schema::dropIfExists('emision_pagos');
        Schema::dropIfExists('ecocadiograma_fetal');
        Schema::dropIfExists('eco_pelvico');
        Schema::dropIfExists('eco_obstetrico_tercer_o');
        Schema::dropIfExists('eco_obstetrico_tercer_2_o');
        Schema::dropIfExists('eco_obstetrico_tercer_2');
        Schema::dropIfExists('eco_obstetrico_tercer');
        Schema::dropIfExists('eco_obstetrico');
        Schema::dropIfExists('eco_doppler');
        Schema::dropIfExists('DUMMY');
        Schema::dropIfExists('doctores');
        Schema::dropIfExists('dieta_paciente');
        Schema::dropIfExists('dias_semana');
        Schema::dropIfExists('diagnosticos');
        Schema::dropIfExists('diagnostico_paciente');
        Schema::dropIfExists('detalles_presupuesto_plantilla');
        Schema::dropIfExists('detalles_factura_cliente');
        Schema::dropIfExists('department');
        Schema::dropIfExists('dd_arterial_mi');
        Schema::dropIfExists('cuentas_x_pagar_mov');
        Schema::dropIfExists('cuentas_x_pagar');
        Schema::dropIfExists('consultorios');
        Schema::dropIfExists('consultas');
        Schema::dropIfExists('constancia_obs');
        Schema::dropIfExists('cola_dia_no_labor');
        Schema::dropIfExists('cola');
        Schema::dropIfExists('clinicas');
        Schema::dropIfExists('baremo_quiru');
        Schema::dropIfExists('bancos');
        Schema::dropIfExists('antecedentes');
        Schema::dropIfExists('antece_paciente');
    }
};