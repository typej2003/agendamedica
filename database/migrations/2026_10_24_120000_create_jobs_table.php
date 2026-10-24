<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **Tabla `jobs`: la cola en la base** (2026-10-24).
 *
 * Hasta acá producción corría con `QUEUE_CONNECTION=sync`, así que el único listener encolado
 * (`GuardarUltimaActividadWeb`) se ejecutaba dentro del request. Con `database`, el job se escribe acá y lo
 * ejecuta el worker que despierta `schedule:run` (`queue:work --stop-when-empty`, ver `Console\Kernel`).
 *
 * La tabla la trae el esqueleto de Laravel en un proyecto nuevo; este repo arrastra las migraciones del
 * Laravel 8 y nunca la tuvo. Es **aditiva y con guarda** (trampa 167): si alguien ya la creó a mano en el
 * servidor, la migración no falla. `failed_jobs` **no** se toca: existe desde 2019 y ya tiene el `uuid` que
 * espera `QUEUE_FAILED_DRIVER=database-uuids`.
 */
class CreateJobsTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('jobs')) {
            return;
        }

        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }

    public function down()
    {
        Schema::dropIfExists('jobs');
    }
}
