<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `historias.numhistoria` era único **global**, pero en el legado cada instalación de PowerBuilder
 * arranca su correlativo en 00001: la historia 1 de un médico y la de otro son pacientes distintos.
 * Con la unicidad global, el segundo médico que subiera su historia 1 hacía fallar
 * `PacienteSyncController`, y el correlativo por médico del Paso 18.B (llenar historia desde el app)
 * era imposible. Pasa a ser único por `(reg_medico, numhistoria)`.
 *
 * `historias` es tabla de AppDDR (no viene del dump legado), así que se puede reestructurar.
 */
class HistoriasNumhistoriaUnicaPorMedico extends Migration
{
    public function up()
    {
        Schema::table('historias', function (Blueprint $table) {
            $table->dropUnique('historias_numhistoria_unique');
            $table->unique(['reg_medico', 'numhistoria'], 'historias_reg_medico_numhistoria_unique');
        });
    }

    public function down()
    {
        Schema::table('historias', function (Blueprint $table) {
            $table->dropUnique('historias_reg_medico_numhistoria_unique');
            $table->unique('numhistoria');
        });
    }
}
