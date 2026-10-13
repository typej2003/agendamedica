<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tablas de **pediatría** (PediaReport / `Ddrpedia`) que el API no tenía: 56 de las 140 de la base
 * real del escritorio. Es el primer paso del sync de la segunda especialidad (WEB-7.4.1).
 *
 * Las definiciones salen TAL CUAL del esquema real de esa instalación
 * (`Docs/MICONSULTAGI-legado/esquemas-asa/pediatria.sql`, unload de SQL Anywhere 8.0.1.2600), no se
 * inventaron: mismas columnas, tipos y nulabilidad, más `id`, `reg_medico` y fechas como el resto de
 * las tablas del legado. Las generó `bridge/tools/diff-columnas-especialidades.php --migracion=pediatria`
 * y se revisaron a mano (2026-10-13): no quedó ningún tipo sin mapear y ninguna tabla traía ya un
 * `id`/`reg_medico` propio.
 *
 * Tres familias, por cómo las usa el escritorio ([`Docs/Web/relevamiento-pediatria.md`](../../../Docs/Web/relevamiento-pediatria.md)):
 *  - **Crecimiento**: `grafica_*` (una fila por fecha y medida) y los percentiles de referencia
 *    (`percentil_nino`, `percentil_nina`, `percentil_pa_*`, `percentil_pulso_a_m`, `percentiles_paciente`).
 *  - **Control de niño sano**: `vacunas`, `vacuna_paciente`, `vacunas_dosis`, `tipo_vacuna`,
 *    `vacunas_aplicadas`, `vacunas_compra`, `vacunas_compradas`, `vacunas_salidas`, `alertas*`,
 *    `tareas*`, `dietas`, `cuestionario`.
 *  - **Paciente pediátrico y su familia**: `paciente_chamo` (el chamo, con su representante y sus
 *    banderas `pediatrico`/`vacunas`), `familia_bebe` (la familia, con los datos de los padres).
 *  Más las de otras pantallas que el escritorio de pediatría trae: `ultra_*`, `endosc_diges_*`,
 *  `espiro`, `grafi_*`, `pre_aneste`, `riesgo*`, `interven_consulta`, `presupuesto`.
 *
 * `hasTable` como el resto: el servidor de desarrollo puede tener alguna creada a mano. `down()` no
 * borra nada (ver la nota de `create_tablas_legado_faltantes`).
 *
 * Lo que **no** entra acá y hay que resolver antes de cerrar el manifiesto de pediatría: 14 tablas
 * que los DataWindows/código de pediatría escriben y que la base de la que salió este esquema no
 * tiene (`paciente_vacuven`, `antecente_fijo`, `intervenciones`, `trata_insitu*`, `grafica_paso_talla`,
 * `factura_maestro_final`, `recipes_psico`, `pacientes_copy_copy`, `antece_pacientes_3` y las locales
 * `activar*`/`usuario(s)`): hace falta el DSN de la base real (WEB-0.2c).
 */
return new class extends Migration
{
    public function up(): void
    {
        // presupuesto   (clave del escritorio: nropresupuesto)
        if (! Schema::hasTable('presupuesto')) {
            Schema::create('presupuesto', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->string('cedula', 8)->nullable();
                $table->string('nombre', 45)->nullable();
                $table->string('direccion', 60)->nullable();
                $table->string('telefono', 20)->nullable();
                $table->decimal('nropresupuesto', 8, 0);
                $table->date('fecha')->nullable();
                $table->string('cliente', 50)->nullable();
                $table->text('nota')->nullable();
                $table->integer('numhistoria')->nullable();
                $table->timestamps();
            });
        }

        // familia_bebe   (clave del escritorio: familia)
        if (! Schema::hasTable('familia_bebe')) {
            Schema::create('familia_bebe', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('familia');
                $table->text('familia_texto')->nullable();
                $table->string('ced_representante', 10)->nullable();
                $table->string('nom_padre', 120)->nullable();
                $table->string('nom_madre', 120)->nullable();
                $table->date('fecn_padre')->nullable();
                $table->date('fecn_madre')->nullable();
                $table->string('ocupa_padre', 60)->nullable();
                $table->string('ocupa_madre', 60)->nullable();
                $table->string('situacion_pareja', 1)->nullable();
                $table->text('dinamica_familiar')->nullable();
                $table->string('localidad', 200)->nullable();
                $table->text('direccion')->nullable();
                $table->string('celu_madre', 12)->nullable();
                $table->string('cel_padre', 12)->nullable();
                $table->string('telefono', 12)->nullable();
                $table->string('procedencia', 2)->nullable();
                $table->string('email', 120)->nullable();
                $table->date('fecha_ingreso')->nullable();
                $table->string('status', 1)->nullable();
                $table->integer('medico')->nullable();
                $table->string('sms', 1)->nullable();
                $table->timestamps();
            });
        }

        // paciente_chamo   (clave del escritorio: historia)
        if (! Schema::hasTable('paciente_chamo')) {
            Schema::create('paciente_chamo', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('familia');
                $table->integer('historia');
                $table->string('cedula', 10)->nullable();
                $table->string('nombre', 200)->nullable();
                $table->string('sexo', 1)->nullable();
                $table->date('f_nacimiento')->nullable();
                $table->text('l_nacimiento')->nullable();
                $table->string('representante', 10)->nullable();
                $table->date('f_ingreso')->nullable();
                $table->string('escolaridad', 100)->nullable();
                $table->string('segu_empre', 2)->nullable();
                $table->text('foto_paciente')->nullable();
                $table->string('celular', 12)->nullable();
                $table->string('email', 120)->nullable();
                $table->text('nota_general')->nullable();
                $table->text('alergia')->nullable();
                $table->text('notas')->nullable();
                $table->integer('medico')->nullable();
                $table->string('sitio', 1)->nullable();
                $table->string('medio', 1)->nullable();
                $table->string('medio_publi', 100)->nullable();
                $table->string('medio_otro', 100)->nullable();
                $table->string('pediatrico', 1)->nullable();
                $table->string('vacunas', 1)->nullable();
                $table->timestamps();
            });
        }

        // cuestionario   (clave del escritorio: nrohistoria + nroconsulta)
        if (! Schema::hasTable('cuestionario')) {
            Schema::create('cuestionario', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('nrohistoria');
                $table->integer('nroconsulta');
                $table->text('digestivo')->nullable();
                $table->text('respiratorio')->nullable();
                $table->text('circulatorio')->nullable();
                $table->text('musculoesqueletico')->nullable();
                $table->text('nerviosa')->nullable();
                $table->text('urinario')->nullable();
                $table->text('reproductor')->nullable();
                $table->text('sentidos')->nullable();
                $table->text('denticion')->nullable();
                $table->text('lactancia')->nullable();
                $table->text('otras')->nullable();
                $table->timestamps();
            });
        }

        // percentiles_paciente   (clave del escritorio: hhistoria + hconsulta)
        if (! Schema::hasTable('percentiles_paciente')) {
            Schema::create('percentiles_paciente', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('hhistoria');
                $table->integer('hconsulta');
                $table->float('peso')->nullable();
                $table->float('talla')->nullable();
                $table->float('c_cefalica')->nullable();
                $table->float('c_brazo')->nullable();
                $table->float('pulso')->nullable();
                $table->float('p_a_sistolica')->nullable();
                $table->float('p_a_diastolica')->nullable();
                $table->date('fecha')->nullable();
                $table->float('imc')->nullable();
                $table->timestamps();
            });
        }

        // tareas_paciente   (clave del escritorio: hhistoria)
        if (! Schema::hasTable('tareas_paciente')) {
            Schema::create('tareas_paciente', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('hhistoria');
                $table->string('cod_tarea', 2)->nullable();
                $table->string('descripcion', 100)->nullable();
                $table->date('fecha_creada')->nullable();
                $table->date('fecha_tarea')->nullable();
                $table->date('fecha_ejecutada')->nullable();
                $table->string('ejecutada', 1)->nullable();
                $table->timestamps();
            });
        }

        // alertas_paciente   (clave del escritorio: historia)
        if (! Schema::hasTable('alertas_paciente')) {
            Schema::create('alertas_paciente', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('historia');
                $table->string('cod_alerta', 2)->nullable();
                $table->text('detalle')->nullable();
                $table->date('fecha_creada')->nullable();
                $table->string('status', 1)->nullable();
                $table->timestamps();
            });
        }

        // alertas   (clave del escritorio: cod_alerta)
        if (! Schema::hasTable('alertas')) {
            Schema::create('alertas', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->string('cod_alerta', 2);
                $table->text('descripcion')->nullable();
                $table->timestamps();
            });
        }

        // tareas   (clave del escritorio: cod_tarea)
        if (! Schema::hasTable('tareas')) {
            Schema::create('tareas', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->string('cod_tarea', 2);
                $table->text('tarea')->nullable();
                $table->timestamps();
            });
        }

        // interven_consulta   (clave del escritorio: historia + consulta + codigo_inter)
        if (! Schema::hasTable('interven_consulta')) {
            Schema::create('interven_consulta', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('historia');
                $table->integer('consulta');
                $table->string('codigo_inter', 3);
                $table->text('detalle')->nullable();
                $table->string('descripcion', 100)->nullable();
                $table->timestamps();
            });
        }

        // percentil_nina   (clave del escritorio: edad_ini_dia + edad_fin_dia)
        if (! Schema::hasTable('percentil_nina')) {
            Schema::create('percentil_nina', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->float('peso_b')->nullable();
                $table->float('peso_m')->nullable();
                $table->float('peso_a')->nullable();
                $table->float('talla_b')->nullable();
                $table->float('talla_m')->nullable();
                $table->float('talla_a')->nullable();
                $table->float('cefalica_b')->nullable();
                $table->float('cefalica_m')->nullable();
                $table->float('cefalica_a')->nullable();
                $table->float('brazo_b')->nullable();
                $table->float('brazo_m')->nullable();
                $table->float('brazo_a')->nullable();
                $table->integer('edad_ini_dia');
                $table->integer('edad_fin_dia');
                $table->string('edad_texto', 100)->nullable();
                $table->integer('edad_meses')->nullable();
                $table->float('anos')->nullable();
                $table->float('imc_b')->nullable();
                $table->float('imc_m')->nullable();
                $table->float('imc_a')->nullable();
                $table->timestamps();
            });
        }

        // percentil_nino   (clave del escritorio: edad_ini_dia + edad_fin_dia)
        if (! Schema::hasTable('percentil_nino')) {
            Schema::create('percentil_nino', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->float('peso_b')->nullable();
                $table->float('peso_m')->nullable();
                $table->float('peso_a')->nullable();
                $table->float('talla_b')->nullable();
                $table->float('talla_m')->nullable();
                $table->float('talla_a')->nullable();
                $table->float('cefalica_b')->nullable();
                $table->float('cefalica_m')->nullable();
                $table->float('cefalica_a')->nullable();
                $table->float('brazo_b')->nullable();
                $table->float('brazo_m')->nullable();
                $table->float('brazo_a')->nullable();
                $table->integer('edad_ini_dia');
                $table->integer('edad_fin_dia');
                $table->string('edad_texto', 100)->nullable();
                $table->integer('edad_meses')->nullable();
                $table->float('anos')->nullable();
                $table->float('imc_b')->nullable();
                $table->float('imc_m')->nullable();
                $table->float('imc_a')->nullable();
                $table->timestamps();
            });
        }

        // vacuna_paciente   (clave del escritorio: historia + codi_vacuna + fecha_ejecutada)
        if (! Schema::hasTable('vacuna_paciente')) {
            Schema::create('vacuna_paciente', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('historia');
                $table->string('codi_vacuna', 4);
                $table->string('dosis', 50)->nullable();
                $table->date('fecha_planeada')->nullable();
                $table->date('fecha_ejecutada');
                $table->string('ejecutada', 1)->nullable();
                $table->text('nota')->nullable();
                $table->string('descripcion', 100)->nullable();
                $table->date('fecha_proxima_dosis')->nullable();
                $table->integer('dosis_numero')->nullable();
                $table->float('edad_aplicada')->nullable();
                $table->text('comentario')->nullable();
                $table->string('prioridad', 1)->nullable();
                $table->date('fecha_vence')->nullable();
                $table->date('fecha_proxima_vac')->nullable();
                $table->string('lote', 20)->nullable();
                $table->string('via', 20)->nullable();
                $table->string('zona', 20)->nullable();
                $table->string('tipo_vacu', 10)->nullable();
                $table->string('aplicada_aca', 1)->nullable();
                $table->timestamps();
            });
        }

        // vacunas   (clave del escritorio: codi_vacuna)
        if (! Schema::hasTable('vacunas')) {
            Schema::create('vacunas', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->string('codi_vacuna', 4);
                $table->string('vacuna', 100);
                $table->integer('edad_inicial_meses')->nullable();
                $table->integer('frecuencia')->nullable();
                $table->integer('total')->nullable();
                $table->string('cobertura', 200)->nullable();
                $table->string('dosis', 50)->nullable();
                $table->string('fija', 1)->nullable();
                $table->integer('edad_final_meses')->nullable();
                $table->string('sexo', 1)->nullable();
                $table->integer('cantidad')->nullable();
                $table->float('preciov')->nullable();
                $table->string('tipo_vacu', 10)->nullable();
                $table->string('adulto_ni', 1)->nullable();
                $table->string('prioridad', 1)->nullable();
                $table->string('inventa_propia', 1)->nullable();
                $table->timestamps();
            });
        }

        // grafica_peso   (clave del escritorio: historia + edad + peso + medida)
        if (! Schema::hasTable('grafica_peso')) {
            Schema::create('grafica_peso', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('historia');
                $table->float('edad');
                $table->float('peso');
                $table->string('medida', 20);
                $table->date('fecha');
                $table->string('sexo', 1);
                $table->timestamps();
            });
        }

        // grafica_talla   (clave del escritorio: historia + edad + talla + medida)
        if (! Schema::hasTable('grafica_talla')) {
            Schema::create('grafica_talla', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('historia');
                $table->float('edad');
                $table->float('talla');
                $table->string('medida', 20);
                $table->date('fecha');
                $table->string('sexo', 1);
                $table->timestamps();
            });
        }

        // percentil_pulso_a_m   (clave del escritorio: edad_ini_dia + edad_fin_dia)
        if (! Schema::hasTable('percentil_pulso_a_m')) {
            Schema::create('percentil_pulso_a_m', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->float('tres')->nullable();
                $table->float('diez')->nullable();
                $table->float('veinte_5')->nullable();
                $table->float('cincuenta')->nullable();
                $table->float('setenta_5')->nullable();
                $table->float('noventa')->nullable();
                $table->float('noventa_7')->nullable();
                $table->string('sexo', 1)->nullable();
                $table->integer('edad_ini_dia');
                $table->integer('edad_fin_dia');
                $table->string('edad_texto', 100)->nullable();
                $table->integer('edad_meses')->nullable();
                $table->float('anos')->nullable();
                $table->timestamps();
            });
        }

        // percentil_pa_sisto_m   (clave del escritorio: edad_ini_dia + edad_fin_dia)
        if (! Schema::hasTable('percentil_pa_sisto_m')) {
            Schema::create('percentil_pa_sisto_m', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->float('tres')->nullable();
                $table->float('diez')->nullable();
                $table->float('veinte_5')->nullable();
                $table->float('cincuenta')->nullable();
                $table->float('setenta_5')->nullable();
                $table->float('noventa')->nullable();
                $table->float('noventa_7')->nullable();
                $table->float('cefalica_m')->nullable();
                $table->float('cefalica_a')->nullable();
                $table->string('sexo', 1)->nullable();
                $table->integer('edad_ini_dia');
                $table->integer('edad_fin_dia');
                $table->string('edad_texto', 100)->nullable();
                $table->integer('edad_meses')->nullable();
                $table->float('anos')->nullable();
                $table->timestamps();
            });
        }

        // percentil_pa_diast_m   (clave del escritorio: edad_ini_dia + edad_fin_dia)
        if (! Schema::hasTable('percentil_pa_diast_m')) {
            Schema::create('percentil_pa_diast_m', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->float('tres')->nullable();
                $table->float('diez')->nullable();
                $table->float('veinte_5')->nullable();
                $table->float('cincuenta')->nullable();
                $table->float('setenta_5')->nullable();
                $table->float('noventa')->nullable();
                $table->float('noventa_7')->nullable();
                $table->float('cefalica_m')->nullable();
                $table->float('cefalica_a')->nullable();
                $table->string('sexo', 1)->nullable();
                $table->integer('edad_ini_dia');
                $table->integer('edad_fin_dia');
                $table->string('edad_texto', 100)->nullable();
                $table->integer('edad_meses')->nullable();
                $table->float('anos')->nullable();
                $table->timestamps();
            });
        }

        // grafica_peso_talla   (clave del escritorio: historia + talla + peso + medida)
        if (! Schema::hasTable('grafica_peso_talla')) {
            Schema::create('grafica_peso_talla', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('historia');
                $table->float('talla');
                $table->float('peso');
                $table->string('medida', 20);
                $table->date('fecha');
                $table->string('sexo', 1);
                $table->timestamps();
            });
        }

        // grafica_imc   (clave del escritorio: historia + edad + imc + medida)
        if (! Schema::hasTable('grafica_imc')) {
            Schema::create('grafica_imc', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('historia');
                $table->float('edad');
                $table->float('imc');
                $table->string('medida', 20);
                $table->date('fecha');
                $table->string('sexo', 1);
                $table->timestamps();
            });
        }

        // dietas   (clave del escritorio: cod_dieta)
        if (! Schema::hasTable('dietas')) {
            Schema::create('dietas', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->string('cod_dieta', 2);
                $table->text('descripcion')->nullable();
                $table->float('calorias')->nullable();
                $table->string('dietas', 200)->nullable();
                $table->timestamps();
            });
        }

        // ultra_renal 
        if (! Schema::hasTable('ultra_renal')) {
            Schema::create('ultra_renal', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('historia');
                $table->integer('consulta');
                $table->date('fecha')->nullable();
                $table->text('tex_basico')->nullable();
                $table->float('rd_longitud')->nullable();
                $table->float('rd_transverso')->nullable();
                $table->float('rd_anteroporteior')->nullable();
                $table->text('rd_c_caracteristicas')->nullable();
                $table->float('rd_c_medidas')->nullable();
                $table->string('rd_ha_loe_ubicacion', 100)->nullable();
                $table->float('rd_ha_loe_medida')->nullable();
                $table->float('rd_ha_litiasis_numero')->nullable();
                $table->string('rd_ha_litiasis_ubicacion', 100)->nullable();
                $table->float('rd_ha_litiasis_medida')->nullable();
                $table->float('rd_ha_litiasis_medida_o')->nullable();
                $table->string('rd_ha_dilatacion_sc', 10)->nullable();
                $table->float('ri_longitud')->nullable();
                $table->float('ri_transverso')->nullable();
                $table->float('ri_anteroporteior')->nullable();
                $table->text('ri_c_caracteristicas')->nullable();
                $table->float('ri_c_medidas')->nullable();
                $table->string('ri_ha_loe_ubicacion', 100)->nullable();
                $table->float('ri_ha_loe_medida')->nullable();
                $table->float('ri_ha_litiasis_numero')->nullable();
                $table->string('ri_ha_litiasis_ubicacion', 100)->nullable();
                $table->float('ri_ha_litiasis_medida')->nullable();
                $table->float('ri_ha_litiasis_medida_o')->nullable();
                $table->string('ri_ha_dilatacion_sc', 10)->nullable();
                $table->text('conlusion')->nullable();
                $table->text('sugerencia')->nullable();
                $table->integer('medico_1')->nullable();
                $table->integer('medico_2')->nullable();
                $table->string('motivo_estudio', 100)->nullable();
                $table->float('rd_ha_loe_medida_o')->nullable();
                $table->float('ri_ha_loe_medida_o')->nullable();
                $table->string('equipo', 20)->nullable();
                $table->string('transductor', 20)->nullable();
                $table->float('frecuencia')->nullable();
                $table->timestamps();
            });
        }

        // ultra_tiroides 
        if (! Schema::hasTable('ultra_tiroides')) {
            Schema::create('ultra_tiroides', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('historia');
                $table->integer('consulta');
                $table->date('fecha')->nullable();
                $table->string('transductor', 30)->nullable();
                $table->integer('referido')->nullable();
                $table->text('texto')->nullable();
                $table->text('conclusiones')->nullable();
                $table->timestamps();
            });
        }

        // ultra_vias_urinarias 
        if (! Schema::hasTable('ultra_vias_urinarias')) {
            Schema::create('ultra_vias_urinarias', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('historia');
                $table->integer('consulta');
                $table->date('fecha')->nullable();
                $table->integer('referido')->nullable();
                $table->string('transductor', 30)->nullable();
                $table->text('rinon_derecho')->nullable();
                $table->text('rinon_izquierdo')->nullable();
                $table->text('vejiga_urinaria')->nullable();
                $table->text('prostata')->nullable();
                $table->text('vol_residual_v_p_m')->nullable();
                $table->text('otros')->nullable();
                $table->text('conclusion')->nullable();
                $table->timestamps();
            });
        }

        // vacunas_compra 
        if (! Schema::hasTable('vacunas_compra')) {
            Schema::create('vacunas_compra', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('num_compra');
                $table->string('cod_proveedor', 4);
                $table->date('fecha');
                $table->string('factura', 20);
                $table->float('preciot')->nullable();
                $table->timestamps();
            });
        }

        // vacunas_aplicadas 
        if (! Schema::hasTable('vacunas_aplicadas')) {
            Schema::create('vacunas_aplicadas', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->string('codi_vacuna', 4);
                $table->integer('historia');
                $table->integer('consulta');
                $table->date('fecha');
                $table->integer('dosis')->nullable();
                $table->string('paciente', 100)->nullable();
                $table->string('des_vacuna', 100)->nullable();
                $table->integer('cantidad')->nullable();
                $table->float('preciovu')->nullable();
                $table->float('preciovt')->nullable();
                $table->string('cod_segu', 3)->nullable();
                $table->integer('medico')->nullable();
                $table->timestamps();
            });
        }

        // vacunas_compradas 
        if (! Schema::hasTable('vacunas_compradas')) {
            Schema::create('vacunas_compradas', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->string('codi_vacuna', 4);
                $table->string('cod_proveedor', 4);
                $table->date('fecha');
                $table->string('des_vacuna', 100)->nullable();
                $table->integer('cantidad')->nullable();
                $table->string('factura', 20);
                $table->float('precioc')->nullable();
                $table->timestamps();
            });
        }

        // espiro 
        if (! Schema::hasTable('espiro')) {
            Schema::create('espiro', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('historia');
                $table->integer('consulta');
                $table->float('pef')->nullable();
                $table->float('pve')->nullable();
                $table->text('texto')->nullable();
                $table->timestamps();
            });
        }

        // endosc_diges_infer 
        if (! Schema::hasTable('endosc_diges_infer')) {
            Schema::create('endosc_diges_infer', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('historia');
                $table->integer('consulta');
                $table->date('fecha')->nullable();
                $table->string('equipo', 30)->nullable();
                $table->string('indicacion', 60)->nullable();
                $table->string('sedacion', 60)->nullable();
                $table->string('esofago_estado', 1)->nullable();
                $table->text('esofago_descripcion')->nullable();
                $table->string('estomago_estado', 1)->nullable();
                $table->text('estomago_descripcion')->nullable();
                $table->string('duodeno_estado', 1)->nullable();
                $table->text('duodeno_descripcion')->nullable();
                $table->text('ima_esofago')->nullable();
                $table->text('obs_ima_esofago')->nullable();
                $table->text('ima_estomago')->nullable();
                $table->text('obs_ima_estomago')->nullable();
                $table->text('ima_duodeno')->nullable();
                $table->text('obs_ima_duodeno')->nullable();
                $table->text('conclusiones')->nullable();
                $table->integer('referido')->nullable();
                $table->text('full_texto')->nullable();
                $table->timestamps();
            });
        }

        // endosc_diges_super 
        if (! Schema::hasTable('endosc_diges_super')) {
            Schema::create('endosc_diges_super', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('historia');
                $table->integer('consulta');
                $table->date('fecha')->nullable();
                $table->string('equipo', 30)->nullable();
                $table->string('indicacion', 60)->nullable();
                $table->string('sedacion', 60)->nullable();
                $table->text('descripcion')->nullable();
                $table->text('ima_primera')->nullable();
                $table->text('obs_ima_primera')->nullable();
                $table->text('ima_segunda')->nullable();
                $table->text('obs_ima_segunda')->nullable();
                $table->text('ima_tercera')->nullable();
                $table->text('obs_ima_tercera')->nullable();
                $table->text('conclusiones')->nullable();
                $table->integer('referido')->nullable();
                $table->text('full_texto')->nullable();
                $table->timestamps();
            });
        }

        // examenes_paciente_consulta 
        if (! Schema::hasTable('examenes_paciente_consulta')) {
            Schema::create('examenes_paciente_consulta', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('hhistoria');
                $table->integer('hconsulta');
                $table->float('peso')->nullable();
                $table->float('talla')->nullable();
                $table->float('urea')->nullable();
                $table->float('cr')->nullable();
                $table->float('sodio')->nullable();
                $table->float('potasio')->nullable();
                $table->float('cloro')->nullable();
                $table->date('fecha')->nullable();
                $table->float('calcio')->nullable();
                $table->float('fosforo')->nullable();
                $table->float('magnesio')->nullable();
                $table->float('paratohormona')->nullable();
                $table->float('ferritina')->nullable();
                $table->float('trasferrina')->nullable();
                $table->float('proteinuria')->nullable();
                $table->float('depuracion_creatinina')->nullable();
                $table->float('ac_urico_u')->nullable();
                $table->float('amonio')->nullable();
                $table->float('hierro_serico')->nullable();
                $table->float('folato')->nullable();
                $table->float('creatinina_u')->nullable();
                $table->float('sodio_u')->nullable();
                $table->float('potasio_u')->nullable();
                $table->float('cloro_u')->nullable();
                $table->float('fosforo_u')->nullable();
                $table->float('magnesio_u')->nullable();
                $table->float('citrato_u')->nullable();
                $table->float('oxelato_u')->nullable();
                $table->float('albumina_u')->nullable();
                $table->float('calcio_u')->nullable();
                $table->float('g_v_ph')->nullable();
                $table->float('g_v_pco2')->nullable();
                $table->float('g_v_hco3')->nullable();
                $table->float('g_v_eb')->nullable();
                $table->float('ac_urico_serico')->nullable();
                $table->float('hb')->nullable();
                $table->float('hto')->nullable();
                $table->float('hb_glicosilada')->nullable();
                $table->float('triglicerido')->nullable();
                $table->float('colesterol')->nullable();
                $table->float('creatinina_s')->nullable();
                $table->float('v_urina')->nullable();
                $table->float('creatinina_u_24')->nullable();
                $table->float('sodio_u_24')->nullable();
                $table->float('fosforo_u_24')->nullable();
                $table->float('cloro_u_24')->nullable();
                $table->float('calcio_u_24')->nullable();
                $table->float('aci_urico_u_24')->nullable();
                $table->float('potasio_u_24')->nullable();
                $table->float('oxato_u_24')->nullable();
                $table->float('citrato_u_24')->nullable();
                $table->float('ac_urico')->nullable();
                $table->timestamps();
            });
        }

        // grafi_pr 
        if (! Schema::hasTable('grafi_pr')) {
            Schema::create('grafi_pr', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('historia');
                $table->integer('consulta');
                $table->date('fecha');
                $table->string('medida', 20);
                $table->float('valor');
                $table->timestamps();
            });
        }

        // grafi_po 
        if (! Schema::hasTable('grafi_po')) {
            Schema::create('grafi_po', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('historia');
                $table->integer('consulta');
                $table->date('fecha');
                $table->string('medida', 20);
                $table->float('valor');
                $table->timestamps();
            });
        }

        // grafi_pa 
        if (! Schema::hasTable('grafi_pa')) {
            Schema::create('grafi_pa', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('historia');
                $table->integer('consulta');
                $table->date('fecha');
                $table->string('medida', 20);
                $table->float('valor');
                $table->timestamps();
            });
        }

        // grafi_so 
        if (! Schema::hasTable('grafi_so')) {
            Schema::create('grafi_so', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('historia');
                $table->integer('consulta');
                $table->date('fecha');
                $table->string('medida', 20);
                $table->float('valor');
                $table->timestamps();
            });
        }

        // grafi_tr 
        if (! Schema::hasTable('grafi_tr')) {
            Schema::create('grafi_tr', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('historia');
                $table->integer('consulta');
                $table->date('fecha');
                $table->string('medida', 20);
                $table->float('valor');
                $table->timestamps();
            });
        }

        // grafi_urea 
        if (! Schema::hasTable('grafi_urea')) {
            Schema::create('grafi_urea', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('historia');
                $table->integer('consulta');
                $table->date('fecha');
                $table->string('medida', 20);
                $table->float('valor');
                $table->timestamps();
            });
        }

        // grafi_fo 
        if (! Schema::hasTable('grafi_fo')) {
            Schema::create('grafi_fo', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('historia');
                $table->integer('consulta');
                $table->date('fecha');
                $table->string('medida', 20);
                $table->float('valor');
                $table->timestamps();
            });
        }

        // grafi_fe 
        if (! Schema::hasTable('grafi_fe')) {
            Schema::create('grafi_fe', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('historia');
                $table->integer('consulta');
                $table->date('fecha');
                $table->string('medida', 20);
                $table->float('valor');
                $table->timestamps();
            });
        }

        // grafi_de 
        if (! Schema::hasTable('grafi_de')) {
            Schema::create('grafi_de', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('historia');
                $table->integer('consulta');
                $table->date('fecha');
                $table->string('medida', 20);
                $table->float('valor');
                $table->timestamps();
            });
        }

        // grafi_cl 
        if (! Schema::hasTable('grafi_cl')) {
            Schema::create('grafi_cl', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('historia');
                $table->integer('consulta');
                $table->date('fecha');
                $table->string('medida', 20);
                $table->float('valor');
                $table->timestamps();
            });
        }

        // grafi_ca 
        if (! Schema::hasTable('grafi_ca')) {
            Schema::create('grafi_ca', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('historia');
                $table->integer('consulta');
                $table->date('fecha');
                $table->string('medida', 20);
                $table->float('valor');
                $table->timestamps();
            });
        }

        // grafi_ma 
        if (! Schema::hasTable('grafi_ma')) {
            Schema::create('grafi_ma', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('historia');
                $table->integer('consulta');
                $table->date('fecha');
                $table->string('medida', 20);
                $table->float('valor');
                $table->timestamps();
            });
        }

        // grafi_cr 
        if (! Schema::hasTable('grafi_cr')) {
            Schema::create('grafi_cr', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('historia');
                $table->integer('consulta');
                $table->date('fecha');
                $table->string('medida', 20);
                $table->float('valor');
                $table->timestamps();
            });
        }

        // riesgo_paciente_opera 
        if (! Schema::hasTable('riesgo_paciente_opera')) {
            Schema::create('riesgo_paciente_opera', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('historia');
                $table->integer('puntos')->nullable();
                $table->string('clase', 10)->nullable();
                $table->text('conclusion')->nullable();
                $table->text('recomendaciones')->nullable();
                $table->string('nombre_intervencion', 150)->nullable();
                $table->integer('consulta');
                $table->timestamps();
            });
        }

        // pre_aneste 
        if (! Schema::hasTable('pre_aneste')) {
            Schema::create('pre_aneste', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('historia');
                $table->integer('consulta');
                $table->string('tipo', 1)->nullable();
                $table->string('resultado', 200)->nullable();
                $table->string('emergencia', 1)->nullable();
                $table->timestamps();
            });
        }

        // riesgo 
        if (! Schema::hasTable('riesgo')) {
            Schema::create('riesgo', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('clave');
                $table->integer('puntos')->nullable();
                $table->string('descripcion', 100)->nullable();
                $table->string('sub_tipo', 30)->nullable();
                $table->timestamps();
            });
        }

        // riesgo_paciente 
        if (! Schema::hasTable('riesgo_paciente')) {
            Schema::create('riesgo_paciente', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('historia');
                $table->string('descripcion', 100);
                $table->string('sub_tipo', 30)->nullable();
                $table->integer('punto')->nullable();
                $table->timestamps();
            });
        }

        // grafica_circunferencia_c 
        if (! Schema::hasTable('grafica_circunferencia_c')) {
            Schema::create('grafica_circunferencia_c', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('historia');
                $table->float('edad');
                $table->float('circunferencia');
                $table->string('medida', 20);
                $table->date('fecha')->nullable();
                $table->string('sexo', 1)->nullable();
                $table->timestamps();
            });
        }

        // grafica_brazo 
        if (! Schema::hasTable('grafica_brazo')) {
            Schema::create('grafica_brazo', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('historia');
                $table->float('edad');
                $table->float('brazo');
                $table->string('medida', 20);
                $table->date('fecha')->nullable();
                $table->string('sexo', 1)->nullable();
                $table->timestamps();
            });
        }

        // grafica_presio_d 
        if (! Schema::hasTable('grafica_presio_d')) {
            Schema::create('grafica_presio_d', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('historia');
                $table->float('edad');
                $table->float('presion_d');
                $table->string('medida', 20);
                $table->date('fecha')->nullable();
                $table->string('sexo', 1)->nullable();
                $table->timestamps();
            });
        }

        // grafica_presio_s 
        if (! Schema::hasTable('grafica_presio_s')) {
            Schema::create('grafica_presio_s', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('historia');
                $table->float('edad');
                $table->float('presion_s');
                $table->string('medida', 20);
                $table->date('fecha')->nullable();
                $table->string('sexo', 1)->nullable();
                $table->timestamps();
            });
        }

        // vacunas_dosis 
        if (! Schema::hasTable('vacunas_dosis')) {
            Schema::create('vacunas_dosis', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->string('codi_vacuna', 4);
                $table->integer('dosis');
                $table->string('dosis_descri', 50)->nullable();
                $table->float('meses')->nullable();
                $table->string('obligatoria', 1)->nullable();
                $table->string('sexo', 1)->nullable();
                $table->string('frecuencia', 1)->nullable();
                $table->timestamps();
            });
        }

        // vacunas_salidas 
        if (! Schema::hasTable('vacunas_salidas')) {
            Schema::create('vacunas_salidas', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->string('codi_vacuna', 4);
                $table->integer('historia');
                $table->integer('consulta');
                $table->date('fecha');
                $table->time('hora');
                $table->string('paciente', 100)->nullable();
                $table->string('des_vacuna', 100)->nullable();
                $table->integer('cantidad')->nullable();
                $table->integer('medico')->nullable();
                $table->timestamps();
            });
        }

        // tipo_vacuna 
        if (! Schema::hasTable('tipo_vacuna')) {
            Schema::create('tipo_vacuna', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->string('tipo_vacu', 10);
                $table->string('vacuna', 200)->nullable();
                $table->text('descripcion')->nullable();
                $table->timestamps();
            });
        }

    }

    /** No se borra nada: en desarrollo alguna de estas tablas puede existir con datos. */
    public function down(): void
    {
    }
};
