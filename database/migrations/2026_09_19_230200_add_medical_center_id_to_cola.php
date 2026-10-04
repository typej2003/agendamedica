<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `cola.medical_center_id` — **dónde** es la cita.
 *
 * Hasta ahora la sede de una cita se deducía de `historias.medical_center_id`, que es el centro
 * del **paciente** y no cambia cita a cita: con un médico que atiende en tres sedes, el mismo
 * paciente puede ir al hospital el lunes y a la clínica el martes, y eso no se podía expresar.
 *
 * **No se guarda el `office_id` a propósito** (decisión del usuario): los consultorios son
 * configuración viva —se dan de baja, se les cambia el horario— y una cita no puede quedar
 * colgada de algo que se puede borrar. Con la sede y la hora alcanza: a qué jornada pertenece y
 * si esa jornada llegó a su cupo se calcula en el momento, cruzando la hora contra
 * `office_schedules`.
 *
 * Nullable porque las ~19.900 citas legadas no la tienen, y porque el PowerBuilder va a seguir
 * creando citas sin ella mientras coexista: en esos casos se cae a la historia, como antes.
 */
class AddMedicalCenterIdToCola extends Migration
{
    public function up()
    {
        if (Schema::hasColumn('cola', 'medical_center_id')) {
            return;
        }

        Schema::table('cola', function (Blueprint $table) {
            $table->unsignedBigInteger('medical_center_id')->nullable()->after('numhistoria');
            $table->index(['medical_center_id', 'fecha']);
        });
    }

    public function down()
    {
        if (!Schema::hasColumn('cola', 'medical_center_id')) {
            return;
        }

        Schema::table('cola', function (Blueprint $table) {
            $table->dropIndex(['medical_center_id', 'fecha']);
            $table->dropColumn('medical_center_id');
        });
    }
}
