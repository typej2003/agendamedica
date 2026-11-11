<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **Las tablas propias de Otorrinolaringología** (ORL, 2026-11-10): las once que el escritorio del ORL usa y que no
 * estaban en el esquema del API.
 *
 * Definiciones tomadas del esquema real (`Docs/MICONSULTAGI-legado/esquemas-asa/orl.sql`), con `id`, `reg_medico` y
 * fechas como el resto de las tablas del legado. **Las claves del escritorio se respetan tal cual** (`historia` +
 * `consulta`, y en la audiometría `frecuencia_od`/`frecuencia_oi` y `frecuecia` + `oido`, con el error de tipeo del
 * fuente). Los `long varchar` van como `text`, los `char(n)` de hasta 255 como `char(n)` y los de 256/300 como
 * `string(n)` (MySQL no acepta un `CHAR` de más de 255). Todo nullable salvo lo que el esquema marca `NOT NULL`.
 *
 * `hasTable` por tabla, como el resto; `down()` no borra nada (misma nota que las migraciones aditivas del legado).
 */
return new class extends Migration
{
    public function up(): void
    {
        // La **evaluación física del ORL**, una fila por consulta: faringe, orofaringe, laringe, nariz, rinofaringe,
        // oído, neurológico, cuello, tórax y el resumen del examen.
        if (! Schema::hasTable('fisico_orl')) {
            Schema::create('fisico_orl', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('historia');
                $table->integer('consulta');

                foreach ([
                    'orof_labios', 'orof_dentadura', 'orof_lengua', 'orof_paladar_b', 'orof_paladar_d',
                    'orof_amigdals', 'orof_uvula', 'nar_mucosa', 'nar_cornete_i', 'nar_cornete_m', 'nar_meato_i',
                    'nar_meato_m', 'nar_septum', 'nar_coana', 'rinofaringe', 'oid_pabellon', 'oid_cae', 'oid_membrana',
                    'neurologico', 'cuello_anterio', 'cuello_posterior', 'torax', 'abdomen', 'miembros_si',
                    'articulaciones', 'resumem', 'faringe_naso', 'laringe_supra', 'faringe_hipo', 'laringe_glot',
                    'laringe_infrag', 'faringe', 'laringe',
                ] as $columna) {
                    $table->text($columna)->nullable();
                }

                $table->timestamps();

                $table->index(['reg_medico', 'historia', 'consulta'], 'fisico_orl_consulta');
            });
        }

        // La **audiometría por frecuencia y oído**: una fila por frecuencia (la clave del escritorio la incluye).
        if (! Schema::hasTable('oido_derecho')) {
            Schema::create('oido_derecho', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('historia');
                $table->integer('consulta');
                $table->integer('frecuencia_od');
                $table->float('db_aereo_od')->nullable();
                $table->float('db_osea_od')->nullable();

                $table->timestamps();

                $table->index(['reg_medico', 'historia', 'consulta'], 'oido_derecho_consulta');
            });
        }

        if (! Schema::hasTable('oido_izquierdo')) {
            Schema::create('oido_izquierdo', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('historia');
                $table->integer('consulta');
                $table->integer('frecuencia_oi');
                $table->float('db_aereo_oi')->nullable();
                $table->float('db_osea_oi')->nullable();

                $table->timestamps();

                $table->index(['reg_medico', 'historia', 'consulta'], 'oido_izquierdo_consulta');
            });
        }

        // Las **curvas del audiograma**: `oidos_graficas` y su hermana `oidos_graficas_iz` (la misma forma; el
        // escritorio las borra y las reinserta al abrir la solapa). `frecuecia` y `desibeles` van como en la fuente.
        foreach (['oidos_graficas', 'oidos_graficas_iz'] as $tabla) {
            if (! Schema::hasTable($tabla)) {
                Schema::create($tabla, function (Blueprint $table) use ($tabla) {
                    $table->id();
                    $table->string('reg_medico', 20)->nullable();
                    $table->integer('historia');
                    $table->integer('consulta');
                    $table->integer('frecuecia');
                    $table->float('desibeles')->nullable();
                    $table->char('oido', 15);

                    $table->timestamps();

                    $table->index(['reg_medico', 'historia', 'consulta'], $tabla . '_consulta');
                });
            }
        }

        // El **detalle de la audiometría**: el audiómetro, los porcentajes de pérdida, los umbrales de inteligibilidad,
        // la discriminación, el diagnóstico y la fecha.
        if (! Schema::hasTable('audio_detalle')) {
            Schema::create('audio_detalle', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('historia');
                $table->integer('consulta');

                $table->char('audiometro', 50)->nullable();
                $table->float('porcetaje_perdida_od')->nullable();
                $table->float('porcetaje_perdida_oi')->nullable();
                $table->float('umbra_inteligibilidad_od')->nullable();
                $table->float('umbra_inteligibilidad_oi')->nullable();
                $table->float('porc_discriminacion_od')->nullable();
                $table->float('porc_discriminacion_oi')->nullable();
                $table->text('diagnostico')->nullable();
                $table->text('observaciones')->nullable();
                $table->date('fecha')->nullable();

                $table->timestamps();

                $table->index(['reg_medico', 'historia', 'consulta'], 'audio_detalle_consulta');
            });
        }

        // La **nasofibrolaringoscopía** (versión vieja): sus regiones, el equipo, la sedación, la indicación, la
        // conclusión y las rutas de las tres imágenes de la PC del consultorio.
        if (! Schema::hasTable('NASO')) {
            Schema::create('NASO', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('historia');
                $table->integer('consulta');

                $table->text('fn_cornetes_i_d')->nullable();
                $table->text('fn_cornetes_i_i')->nullable();
                $table->text('fn_cornetes_m_d')->nullable();
                $table->text('fn_cornetes_s_d')->nullable();
                $table->text('fn_meatos_d')->nullable();
                $table->text('fn_tabique')->nullable();
                $table->text('rinofaringe')->nullable();
                $table->char('rinofaringe_adenoides_n', 1)->nullable();
                $table->char('rinofaringe_adenoides_h', 1)->nullable();
                $table->char('larige_e', 1)->nullable();
                $table->text('laringe_r_supraglotica')->nullable();
                $table->char('equipo', 60)->nullable();
                $table->char('sedacion', 60)->nullable();
                $table->char('indicacion', 90)->nullable();
                $table->date('fecha')->nullable();
                $table->text('conclusion')->nullable();
                $table->string('ima_nasa', 300)->nullable();
                $table->string('ima_rinof', 300)->nullable();
                $table->string('ima_farin', 300)->nullable();
                $table->text('fn_cornetes_m_l')->nullable();

                $table->timestamps();

                $table->index(['reg_medico', 'historia', 'consulta'], 'NASO_consulta');
            });
        }

        // `naso_new` y `naso_new_video` son la misma tabla con más regiones (hipofaringe, lengua, epiglotis y la laringe
        // por zonas) y sin `rinofaringe_adenoides_h`.
        foreach (['naso_new', 'naso_new_video'] as $tabla) {
            if (! Schema::hasTable($tabla)) {
                Schema::create($tabla, function (Blueprint $table) use ($tabla) {
                    $table->id();
                    $table->string('reg_medico', 20)->nullable();
                    $table->integer('historia');
                    $table->integer('consulta');

                    $table->text('fn_cornetes_i_d')->nullable();
                    $table->text('fn_cornetes_i_i')->nullable();
                    $table->text('fn_cornetes_m_d')->nullable();
                    $table->text('fn_cornetes_s_d')->nullable();
                    $table->text('fn_meatos_d')->nullable();
                    $table->text('fn_tabique')->nullable();
                    $table->text('rinofaringe')->nullable();
                    $table->char('rinofaringe_adenoides_n', 1)->nullable();
                    $table->text('hipofaringe')->nullable();
                    $table->char('larige_e', 1)->nullable();
                    $table->text('laringe_r_supraglotica')->nullable();
                    $table->char('equipo', 60)->nullable();
                    $table->char('sedacion', 60)->nullable();
                    $table->char('indicacion', 90)->nullable();
                    $table->date('fecha')->nullable();
                    $table->text('conclusion')->nullable();
                    $table->string('ima_nasa', 300)->nullable();
                    $table->string('ima_rinof', 300)->nullable();
                    $table->string('ima_farin', 300)->nullable();
                    $table->text('fn_cornetes_m_l')->nullable();
                    $table->text('fn_cornetes_s_i')->nullable();
                    $table->text('fn_meatos_i')->nullable();
                    $table->text('lengua')->nullable();
                    $table->text('epiglotis')->nullable();
                    $table->text('laringe_r_glotica_aritenoid')->nullable();
                    $table->text('laringe_r_glotica_b_ventri')->nullable();
                    $table->text('laringe_r_glotica_c_vocal')->nullable();
                    $table->text('laringe_r_suglotica')->nullable();

                    $table->timestamps();

                    $table->index(['reg_medico', 'historia', 'consulta'], $tabla . '_consulta');
                });
            }
        }

        // Las **imágenes** de la nasofibrolaringoscopía (la ruta de la PC y su descripción), clave
        // `nrohistoria` + `nroconsulta` + `imagen`.
        foreach (['imagen_nasolarigo', 'imagen_nasovideo'] as $tabla) {
            if (! Schema::hasTable($tabla)) {
                Schema::create($tabla, function (Blueprint $table) use ($tabla) {
                    $table->id();
                    $table->string('reg_medico', 20)->nullable();
                    $table->integer('nrohistoria');
                    $table->integer('nroconsulta');

                    $table->text('observacion')->nullable();
                    $table->string('imagen', 256);
                    $table->integer('orden')->nullable();
                    $table->char('tipo', 1)->nullable();

                    $table->timestamps();

                    $table->index(['reg_medico', 'nrohistoria', 'nroconsulta'], $tabla . '_consulta');
                });
            }
        }
    }

    public function down(): void
    {
        // No borra nada: misma regla que las migraciones aditivas del legado.
    }
};
