<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columnas que el escritorio de **cardiología** (`esquemas-asa/cardiologia.sql`) manda en tablas que ya
 * existen, y que el API no tenía: sin ellas el sync rechaza la fila o pierde el dato.
 *
 * Todas son **nullable**: las otras especialidades no las mandan y su fila tiene que seguir entrando.
 *
 * No se agrega `pacientes.numhistoria`: el escritorio la declara NOT NULL y el API la maneja con
 * `clave_escritorio`/`historia_anterior` (ver la nota de `add_columnas_pediatria_a_tablas_compartidas`).
 * `numhistoria` del escritorio es el número de historia que la web ya resuelve por su propio camino.
 */
return new class extends Migration
{
    public function up(): void
    {
        // La consulta del cardiólogo: el marcapasos y el estudio ecográfico como texto, y los egresos médicos.
        Schema::table('consultas', function (Blueprint $table) {
            foreach (['marcapasos', 'ecografico', 'egresos_medi'] as $columna) {
                if (! Schema::hasColumn('consultas', $columna)) {
                    $table->text($columna)->nullable();
                }
            }
        });

        // El paciente: el historial anterior que trae el escritorio (char(10)).
        Schema::table('pacientes', function (Blueprint $table) {
            if (! Schema::hasColumn('pacientes', 'histo_ant')) {
                $table->string('histo_ant', 10)->nullable();
            }
        });

        // El resumen del riesgo quirúrgico del cardiólogo: las fechas y los puntos de tromboembolismo.
        Schema::table('riesgo_paciente_opera', function (Blueprint $table) {
            foreach (['fecha_i', 'fecha_pre'] as $columna) {
                if (! Schema::hasColumn('riesgo_paciente_opera', $columna)) {
                    $table->date($columna)->nullable();
                }
            }
            if (! Schema::hasColumn('riesgo_paciente_opera', 'puntos_te')) {
                $table->string('puntos_te', 50)->nullable();
            }
        });
    }

    public function down(): void
    {
    }
};
