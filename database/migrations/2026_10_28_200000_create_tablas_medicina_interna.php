<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tablas y columnas de **medicina interna** (MedicReport, instalación `miconsultac`) que el API no tenía.
 *
 * Las definiciones salen TAL CUAL del esquema real de esa instalación
 * (`Docs/MICONSULTAGI-legado/esquemas-asa/cirugia.sql`: los documentos la llamaban «cirugía», es medicina interna),
 * con el borrador de `bridge/tools/diff-columnas-especialidades.php --migracion=cirugia` revisado a mano: mismas
 * columnas, tipos y nulabilidad, más `id`, `reg_medico` y fechas como el resto de las tablas del legado.
 *
 * Entran sólo las que un internista **usa** (filas medidas en la base de un consultorio real el 2026-10-28, ver
 * `Docs/Web/relevamiento-medicina-interna.md`):
 *  - `ekg`: el electrocardiograma de la consulta (3 de cada 4 consultas);
 *  - `datos_grafica`: los valores del «Análisis evolutivo» (una fila por consulta);
 *  - `pre_trombo`, `pre_infec`, `pre_espiro`, `pre_espec`: las escalas de la evaluación pre-operatoria que faltaban
 *    (`pre_aneste` y `riesgo*` ya las creó pediatría).
 *
 * No entran: `ortopedia` (la solapa no está en los menús del escritorio: decisión del usuario, 2026-10-28),
 * `recipes_psico`, `cumanaoca` e `impuestos` (sin uso).
 *
 * Las columnas nuevas de tablas compartidas son **todas nullable**: el escritorio de las otras especialidades no
 * las manda y la fila tiene que seguir entrando. No se agrega `pacientes.numhistoria` (ver la nota de
 * `add_columnas_pediatria_a_tablas_compartidas`).
 *
 * `hasTable`/`hasColumn` como el resto: el servidor de desarrollo puede tener alguna creada a mano. `down()` no borra
 * nada (ver la nota de `create_tablas_legado_faltantes`).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ekg   (clave del escritorio: numhistoria + numconsulta; DataWindow `d_dd_akg`)
        if (! Schema::hasTable('ekg')) {
            Schema::create('ekg', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->string('ritmo', 60)->nullable();
                $table->string('fc', 60)->nullable();
                $table->string('pr', 60)->nullable();
                $table->string('aqrs', 60)->nullable();
                $table->string('qt', 60)->nullable();
                $table->string('ap', 60)->nullable();
                $table->string('at', 60)->nullable();
                $table->string('alteraciones', 60)->nullable();
                $table->text('conclusiones')->nullable();
                $table->integer('numhistoria');
                $table->integer('numconsulta');
                $table->string('hbsai', 10)->nullable();
                $table->string('birdhh', 10)->nullable();
                $table->string('bcrdhh', 10)->nullable();
                $table->string('bav', 10)->nullable();
                $table->string('primer', 10)->nullable();
                $table->string('segundo', 10)->nullable();
                $table->string('tercero', 10)->nullable();
                $table->string('birihh', 10)->nullable();
                $table->string('bcrihh', 10)->nullable();
                $table->text('hallazgos')->nullable();
                $table->timestamps();
            });
        }

        // datos_grafica   (clave del escritorio: historia + consulta). Qué valor guarda cada `otro_N`: el `UPDATE` de
        // `w_consulta_principal.srw:3542` (otro_4 Hb glicosilada … otro_13 colesterol; otro_1-3 y 14 siempre nulos).
        if (! Schema::hasTable('datos_grafica')) {
            Schema::create('datos_grafica', function (Blueprint $table) {
                $table->id();
                $table->string('reg_medico', 20)->nullable();
                $table->integer('historia');
                $table->integer('consulta');
                $table->double('grosor_abdominal')->nullable();
                $table->double('indice_masa')->nullable();
                $table->double('micro_album')->nullable();
                $table->double('otro_1')->nullable();
                $table->text('otro_2')->nullable();
                $table->text('otro_3')->nullable();
                foreach (range(4, 14) as $n) {
                    $table->double('otro_' . $n)->nullable();
                }
                $table->timestamps();
            });
        }

        // Escalas de la evaluación pre-operatoria (clave del escritorio: historia + consulta; una fila por consulta).
        foreach (['pre_trombo' => null, 'pre_infec' => null, 'pre_espiro' => 200, 'pre_espec' => null] as $tabla => $largo) {
            if (! Schema::hasTable($tabla)) {
                Schema::create($tabla, function (Blueprint $table) use ($largo) {
                    $table->id();
                    $table->string('reg_medico', 20)->nullable();
                    $table->integer('historia');
                    $table->integer('consulta');
                    $table->string('tipo', 1)->nullable();
                    $largo === null ? $table->text('resultado')->nullable() : $table->string('resultado', $largo)->nullable();
                    $table->timestamps();
                });
            }
        }

        // La consulta del internista: las tres temperaturas, el examen funcional y el test (Folstein / minimental).
        Schema::table('consultas', function (Blueprint $table) {
            foreach (['tem_otica', 'tem_oral', 'tem_axilar'] as $columna) {
                if (! Schema::hasColumn('consultas', $columna)) {
                    $table->double($columna)->nullable();
                }
            }
            foreach (['funcionae', 'test'] as $columna) {
                if (! Schema::hasColumn('consultas', $columna)) {
                    $table->text($columna)->nullable();
                }
            }
        });

        // El examen físico por sistemas del internista (`cabeza` y `torax` ya los agregó pediatría).
        Schema::table('examen_fisico', function (Blueprint $table) {
            foreach (['piel_mucosas', 'orl', 'cuello_tiroides', 'vascular', 'neurologico', 'otro', 'mucosas', 'genera', 'ojo_de', 'boca_de'] as $columna) {
                if (! Schema::hasColumn('examen_fisico', $columna)) {
                    $table->text($columna)->nullable();
                }
            }
        });

        // Datos laborales del paciente (la ficha de MedicReport).
        Schema::table('pacientes', function (Blueprint $table) {
            foreach (['dependencia_tra', 'cargo', 'tipo_emp'] as $columna) {
                if (! Schema::hasColumn('pacientes', $columna)) {
                    $table->string($columna, 80)->nullable();
                }
            }
        });
    }

    public function down(): void
    {
    }
};
