<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **Los resultados de exámenes del internista** (O-49, 2026-11-02): la columna del texto libre de la sección
 * *Resultados de exámenes* de la consulta de medicina interna, el que se carga a mano o leyendo la hoja con
 * «Escanear hoja».
 *
 * Es una **columna nullable nueva** y no se reusa ninguna del legado a propósito: en medicina interna el
 * escritorio rotula `consultas.oido` como «EXAMEN FÍSICO» y `consultas.nariz` como «TIPO DE OPERACIÓN»
 * (`fuentes-cirugia/datawin_reportes/d_preoperatoria_imprimir.srd`), así que escribir el resultado ahí
 * mezclaría dos datos distintos en la misma columna. `resultadoexamencomp` tampoco sirve: es el resumen
 * físico que arma «Actualizar».
 *
 * La columna es aditiva y nullable: las filas que ya existen no se tocan y el escritorio, que no la conoce,
 * la ignora. No viaja por el sync como un dato del escritorio: la escribe la web y queda en `sync_changes`
 * por columna, como cualquier otro texto de la consulta.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('consultas') || Schema::hasColumn('consultas', 'resultados_examenes')) {
            return;
        }

        Schema::table('consultas', function (Blueprint $table) {
            $table->longText('resultados_examenes')->nullable()->after('resultadoexamencomp');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('consultas') && Schema::hasColumn('consultas', 'resultados_examenes')) {
            Schema::table('consultas', function (Blueprint $table) {
                $table->dropColumn('resultados_examenes');
            });
        }
    }
};
