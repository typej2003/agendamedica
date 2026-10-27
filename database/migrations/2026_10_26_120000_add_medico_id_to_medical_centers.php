<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `medical_centers.medico_id` — **la sede pasa a ser de un médico** (observación de Alexander, 2026-10-26).
 *
 * Hasta acá la sede era del **catálogo de la clínica** (decisión del 2026-10-09) y el desplegable de «Nuevo
 * consultorio» ofrecía las sedes de todos: «no debe ver las sedes de otros doctores, cada doctor tiene sus
 * sedes». La columna guarda al **dueño** de la sede; `NULL` es una sede vieja o compartida, que se sigue
 * viendo por los consultorios que el médico tenga en ella (la vía de la agenda).
 *
 * **Sin foreign key**: SQLite no la necesita y el lugar nunca se borra (la cita guarda `medical_center_id`).
 * Los borrados de médicos no tienen que arrastrarse la sede de nadie.
 *
 * **Relleno de los datos que ya existen**: para cada sede sin dueño, si **exactamente un** `offices.medico_id`
 * distinto y no nulo le apunta, se le asigna ése. Con dos médicos con consultorio en la misma sede no hay
 * dueño que deducir y queda `NULL` (cada uno la verá por su consultorio).
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('medical_centers') || Schema::hasColumn('medical_centers', 'medico_id')) {
            return;
        }

        Schema::table('medical_centers', function (Blueprint $table) {
            $table->unsignedBigInteger('medico_id')->nullable()->after('id')->index();
        });

        $this->rellenar();
    }

    public function down(): void
    {
        if (! Schema::hasColumn('medical_centers', 'medico_id')) {
            return;
        }

        // Primero el índice: SQLite no deja soltar una columna indexada.
        Schema::table('medical_centers', function (Blueprint $table) {
            $table->dropIndex(['medico_id']);
        });

        Schema::table('medical_centers', function (Blueprint $table) {
            $table->dropColumn('medico_id');
        });
    }

    private function rellenar(): void
    {
        if (! Schema::hasTable('offices') || ! Schema::hasColumn('offices', 'medico_id')) {
            return;
        }

        $duenios = DB::table('offices')
            ->select('medical_center_id', DB::raw('MIN(medico_id) as medico_id'), DB::raw('COUNT(DISTINCT medico_id) as cuantos'))
            ->whereNotNull('medical_center_id')
            ->whereNotNull('medico_id')
            ->groupBy('medical_center_id')
            ->get();

        foreach ($duenios as $fila) {
            if ((int) $fila->cuantos !== 1) {
                continue;
            }

            DB::table('medical_centers')
                ->where('id', $fila->medical_center_id)
                ->whereNull('medico_id')
                ->update(['medico_id' => $fila->medico_id]);
        }
    }
};
